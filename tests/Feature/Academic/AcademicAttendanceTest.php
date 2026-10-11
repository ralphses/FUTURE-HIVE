<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Contexts\Academic\Domain\Models\AcademicAttendanceCorrection;
use App\Contexts\Academic\Domain\Models\AcademicAttendanceEntry;
use App\Contexts\Academic\Domain\Models\AcademicAttendanceRegister;
use App\Contexts\Academic\Domain\Models\AcademicClassArm;
use App\Contexts\Academic\Domain\Models\AcademicLevel;
use App\Contexts\Academic\Domain\Models\AcademicSection;
use App\Contexts\Academic\Domain\Models\AcademicSession;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Domain\Models\ClassTeacherAssignment;
use App\Contexts\Registry\Domain\Models\StaffProfile;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AcademicAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private string $privateKey = '';

    private string $publicKey = '';

    protected function setUp(): void
    {
        parent::setUp();
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $this->privateKey));
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $this->publicKey = (string) ($details['key'] ?? '');
        config(['auth.jwt.private_key' => $this->privateKey, 'auth.jwt.public_keys' => ['test-key' => $this->publicKey], 'auth.jwt.current_kid' => 'test-key', 'auth.jwt.issuer' => 'https://schoolos.test', 'auth.jwt.audience' => 'schoolos-api']);
    }

    public function test_class_teacher_can_read_and_record_daily_attendance_without_inference(): void
    {
        [$school, $teacher, $session, $term, $classArm, $students] = $this->fixture();
        $token = $this->loginAndSelect($teacher, $school, 'attendance-password');
        $path = $this->path($school, $session, $term, $classArm, '2025-09-02');

        $this->withToken($token)->getJson($path)->assertOk()->assertJsonPath('data.entries.0.status', 'unrecorded')->assertJsonPath('data.entries.1.status', 'unrecorded');
        $this->withToken($token)->putJson($path, ['entries' => [['student_id' => $students[0], 'status' => 'present'], ['student_id' => $students[1], 'status' => 'excused', 'reason' => 'Fictional family reason']]])->assertOk()->assertJsonPath('data.entries.0.status', 'present')->assertJsonPath('data.entries.1.status', 'excused');
        $this->withToken($token)->getJson($path)->assertOk()->assertJsonPath('data.entries.0.status', 'present')->assertJsonPath('data.entries.1.status', 'excused');
    }

    public function test_unassigned_teacher_cannot_access_a_class_attendance_register(): void
    {
        [$school, $teacher, $session, $term, $classArm] = $this->fixture();
        $other = UserIdentity::factory()->withPassword('other-password')->create();
        $other->contacts()->update(['canonical_value' => 'other-attendance@example.com', 'verified_at' => now()]);
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $other->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', 'teacher')->value('id'), 'assigned_by' => $other->id, 'assigned_at' => now()]);
        TenantContext::runInternal(TenantContext::fromMembership($membership), 'attendance unassigned teacher fixture', fn (): StaffProfile => StaffProfile::create(['school_id' => $school->id, 'school_membership_id' => $membership->id, 'staff_number' => 'ATT-OTHER', 'legal_name' => 'Fictional Other Teacher', 'employment_status' => 'active']));
        $token = $this->loginAndSelect($other, $school, 'other-password');

        $this->withToken($token)->getJson($this->path($school, $session, $term, $classArm, '2025-09-02'))->assertNotFound();
    }

    public function test_complete_register_can_be_submitted_and_locked_by_leadership(): void
    {
        [$school, $teacher, $session, $term, $classArm, $students] = $this->fixture();
        $teacherToken = $this->loginAndSelect($teacher, $school, 'attendance-password');
        $path = $this->path($school, $session, $term, $classArm, '2025-09-02');

        $this->withToken($teacherToken)->putJson($path, [
            'entries' => [
                ['student_id' => $students[0], 'status' => 'present'],
                ['student_id' => $students[1], 'status' => 'unrecorded'],
            ],
        ])->assertOk();
        $this->withToken($teacherToken)->postJson($path.'/submit')->assertOk()->assertJsonPath('data.register.status', 'submitted');
        $this->withToken($teacherToken)->getJson($path.'/submission')->assertOk()->assertJsonPath('data.submitted', true);
        $this->withToken($teacherToken)->putJson($path, ['entries' => [['student_id' => $students[0], 'status' => 'absent']]])->assertNotFound();

        $leader = UserIdentity::factory()->withPassword('leader-password')->create();
        $leader->contacts()->update(['canonical_value' => 'attendance-leader@example.com', 'verified_at' => now()]);
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $leader->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', 'principal')->value('id'), 'assigned_by' => $leader->id, 'assigned_at' => now()]);
        $leaderToken = $this->loginAndSelect($leader, $school, 'leader-password');

        $this->withToken($leaderToken)->postJson($path.'/lock')->assertOk()->assertJsonPath('data.register.status', 'locked');
        $this->withToken($leaderToken)->postJson($path.'/lock')->assertOk()->assertJsonPath('data.register.status', 'locked');
        $this->withToken($leaderToken)->putJson($path, ['entries' => [['student_id' => $students[0], 'status' => 'present']]])->assertNotFound();
    }

    public function test_submission_requires_a_complete_roster_and_never_infers_absence(): void
    {
        [$school, $teacher, $session, $term, $classArm, $students] = $this->fixture();
        $teacherToken = $this->loginAndSelect($teacher, $school, 'attendance-password');
        $path = $this->path($school, $session, $term, $classArm, '2025-09-02');

        [$register, $classArmId] = TenantContext::runInternal(TenantContext::fromMembership(SchoolMembership::query()->where('user_id', $teacher->id)->where('school_id', $school->id)->firstOrFail()), 'attendance incomplete fixture', function () use ($school, $session, $term, $classArm, $teacher, $students): array {
            $classArmId = AcademicClassArm::query()->where('public_id', $classArm)->value('id');
            $register = AcademicAttendanceRegister::create([
                'school_id' => $school->id,
                'academic_session_id' => AcademicSession::query()->where('public_id', $session)->value('id'),
                'academic_term_id' => AcademicTerm::query()->where('public_id', $term)->value('id'),
                'academic_class_arm_id' => $classArmId,
                'attendance_date' => '2025-09-02',
                'status' => 'draft',
                'created_by' => $teacher->id,
            ]);
            AcademicAttendanceEntry::create([
                'school_id' => $school->id,
                'attendance_register_id' => $register->id,
                'student_id' => Student::query()->where('public_id', $students[0])->value('id'),
                'status' => 'present',
            ]);

            return [$register, $classArmId];
        });

        $response = $this->withToken($teacherToken)->postJson($path.'/submit');
        $response->assertStatus(422);
        self::assertStringContainsString('All currently enrolled students must have an attendance entry before submission.', (string) $response->getContent());
        self::assertSame($classArmId, $register->fresh()->academic_class_arm_id);
        self::assertSame('draft', $register->fresh()->status);
    }

    public function test_submitted_attendance_can_be_corrected_with_append_only_history(): void
    {
        [$school, $teacher, $session, $term, $classArm, $students] = $this->fixture();
        $token = $this->loginAndSelect($teacher, $school, 'attendance-password');
        $path = $this->path($school, $session, $term, $classArm, '2025-09-02');

        $this->withToken($token)->putJson($path, ['entries' => [['student_id' => $students[0], 'status' => 'present'], ['student_id' => $students[1], 'status' => 'unrecorded']]])->assertOk();
        $this->withToken($token)->postJson($path.'/submit')->assertOk();
        $entryId = AcademicAttendanceEntry::query()->whereHas('student', fn ($query): mixed => $query->where('public_id', $students[0]))->value('public_id');

        $correction = $this->withToken($token)->postJson($path.'/corrections', ['entry_id' => $entryId, 'status' => 'excused', 'reason' => 'Fictional appointment', 'correction_reason' => 'Corrected after register review'])
            ->assertOk()
            ->assertJsonPath('data.entry.status', 'excused')
            ->assertJsonPath('data.correction.previous.status', 'present')
            ->assertJsonPath('data.correction.replacement.status', 'excused');
        $correctionId = $correction->json('data.correction.id');

        $this->withToken($token)->postJson($path.'/corrections', ['entry_id' => $entryId, 'status' => 'absent', 'correction_reason' => 'Second fictional review'])
            ->assertOk()
            ->assertJsonPath('data.correction.previous.status', 'excused')
            ->assertJsonPath('data.correction.replacement.status', 'absent');
        $this->withToken($token)->getJson($path.'/corrections')->assertOk()->assertJsonCount(2, 'data.corrections')->assertJsonPath('data.corrections.0.id', $correctionId);
        self::assertSame(2, AcademicAttendanceCorrection::query()->count());
    }

    public function test_locked_attendance_rejects_corrections_and_retains_the_register_state(): void
    {
        [$school, $teacher, $session, $term, $classArm, $students] = $this->fixture();
        $token = $this->loginAndSelect($teacher, $school, 'attendance-password');
        $path = $this->path($school, $session, $term, $classArm, '2025-09-02');

        $this->withToken($token)->putJson($path, ['entries' => [['student_id' => $students[0], 'status' => 'present'], ['student_id' => $students[1], 'status' => 'unrecorded']]])->assertOk();
        $this->withToken($token)->postJson($path.'/submit')->assertOk();

        $leader = UserIdentity::factory()->withPassword('correction-leader-password')->create();
        $leader->contacts()->update(['canonical_value' => 'correction-leader@example.com', 'verified_at' => now()]);
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $leader->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', 'principal')->value('id'), 'assigned_by' => $leader->id, 'assigned_at' => now()]);
        $leaderToken = $this->loginAndSelect($leader, $school, 'correction-leader-password');
        $this->withToken($leaderToken)->postJson($path.'/lock')->assertOk();
        $entryId = AcademicAttendanceEntry::query()->whereHas('student', fn ($query): mixed => $query->where('public_id', $students[0]))->value('public_id');

        $this->withToken($leaderToken)->postJson($path.'/corrections', ['entry_id' => $entryId, 'status' => 'absent', 'correction_reason' => 'Too late'])
            ->assertNotFound();
        $this->withToken($leaderToken)->getJson($path.'/corrections')->assertOk()->assertJsonCount(0, 'data.corrections');
    }

    /** @return array{0: School, 1: UserIdentity, 2: string, 3: string, 4: string, 5: list<string>} */
    private function fixture(): array
    {
        $school = School::factory()->create(['name' => 'Fictional Attendance School']);
        $teacher = UserIdentity::factory()->withPassword('attendance-password')->create();
        $teacher->contacts()->update(['canonical_value' => 'attendance-teacher@example.com', 'verified_at' => now()]);
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $teacher->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', 'teacher')->value('id'), 'assigned_by' => $teacher->id, 'assigned_at' => now()]);

        return TenantContext::runInternal(TenantContext::fromMembership($membership), 'attendance fixture', function () use ($school, $teacher, $membership): array {
            $session = AcademicSession::create(['school_id' => $school->id, 'name' => 'Attendance Session', 'code' => 'ATT', 'start_date' => '2025-09-01', 'end_date' => '2026-07-31', 'status' => 'active']);
            $term = AcademicTerm::create(['school_id' => $school->id, 'academic_session_id' => $session->id, 'name' => 'Attendance Term', 'sequence' => 1, 'start_date' => '2025-09-01', 'end_date' => '2025-12-15', 'status' => 'active']);
            $level = AcademicLevel::create(['school_id' => $school->id, 'name' => 'Attendance Level', 'code' => 'AL', 'sequence' => 1, 'status' => 'active']);
            $section = AcademicSection::create(['school_id' => $school->id, 'academic_level_id' => $level->id, 'name' => 'Attendance Section', 'code' => 'AS', 'sequence' => 1, 'status' => 'active']);
            $classArm = AcademicClassArm::create(['school_id' => $school->id, 'academic_level_id' => $level->id, 'academic_section_id' => $section->id, 'name' => 'Attendance Arm', 'code' => 'AA', 'capacity' => 30, 'status' => 'active']);
            $staff = StaffProfile::create(['school_id' => $school->id, 'school_membership_id' => $membership->id, 'staff_number' => 'ATT-001', 'legal_name' => 'Fictional Attendance Teacher', 'employment_status' => 'active']);
            ClassTeacherAssignment::create(['school_id' => $school->id, 'academic_session_id' => $session->id, 'academic_term_id' => $term->id, 'academic_class_arm_id' => $classArm->id, 'school_membership_id' => $membership->id, 'staff_profile_id' => $staff->id, 'effective_start' => '2025-09-01', 'status' => 'active', 'assigned_by' => $teacher->id, 'assigned_at' => now()]);
            $students = [];
            foreach (['ATT-STU-001', 'ATT-STU-002'] as $number) {
                $student = Student::create(['school_id' => $school->id, 'student_number' => $number, 'display_name' => 'Fictional Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
                StudentEnrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'academic_session_id' => $session->id, 'academic_term_id' => $term->id, 'academic_level_id' => $level->id, 'academic_section_id' => $section->id, 'academic_class_arm_id' => $classArm->id, 'start_date' => '2025-09-01', 'status' => 'active']);
                $students[] = (string) $student->public_id;
            }

            return [$school, $teacher, (string) $session->public_id, (string) $term->public_id, (string) $classArm->public_id, $students];
        });
    }

    private function loginAndSelect(UserIdentity $identity, School $school, string $password): string
    {
        $token = $this->postJson('/api/v1/auth/login', ['login' => $identity->contacts()->firstOrFail()->canonical_value, 'password' => $password])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return $token;
    }

    private function path(School $school, string $session, string $term, string $classArm, string $date): string
    {
        return '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms/'.$term.'/class-arms/'.$classArm.'/attendance/'.$date;
    }
}
