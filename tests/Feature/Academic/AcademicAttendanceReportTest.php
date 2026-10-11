<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

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

final class AcademicAttendanceReportTest extends TestCase
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

    public function test_report_includes_only_submitted_and_locked_registers_and_uses_safe_denominator_rules(): void
    {
        [$school, $teacher, $session, $term, $classArm, $students] = $this->fixture();
        $token = $this->loginAndSelect($teacher, $school, 'report-password');
        $membership = SchoolMembership::query()->where('user_id', $teacher->id)->where('school_id', $school->id)->firstOrFail();

        TenantContext::runInternal(TenantContext::fromMembership($membership), 'attendance report fixture', function () use ($school, $teacher, $session, $term, $classArm, $students): void {
            $sessionId = AcademicSession::query()->where('public_id', $session)->value('id');
            $termId = AcademicTerm::query()->where('public_id', $term)->value('id');
            $classArmId = AcademicClassArm::query()->where('public_id', $classArm)->value('id');
            $studentIds = Student::query()->whereIn('public_id', $students)->orderBy('id')->pluck('id')->all();

            foreach ([['2025-09-02', 'submitted', ['present', 'absent']], ['2025-09-03', 'locked', ['late', 'unrecorded']], ['2025-09-04', 'draft', ['present', 'present']]] as [$date, $status, $statuses]) {
                $register = AcademicAttendanceRegister::create(['school_id' => $school->id, 'academic_session_id' => $sessionId, 'academic_term_id' => $termId, 'academic_class_arm_id' => $classArmId, 'attendance_date' => $date, 'status' => $status, 'created_by' => $teacher->id]);
                foreach ($studentIds as $index => $studentId) {
                    AcademicAttendanceEntry::create(['school_id' => $school->id, 'attendance_register_id' => $register->id, 'student_id' => $studentId, 'status' => $statuses[$index]]);
                }
            }
        });

        $response = $this->withToken($token)->getJson($this->path($school, $session, $term, $classArm).'?from=2025-09-02&to=2025-09-04');
        $response->assertOk()
            ->assertJsonPath('data.summary.submitted_registers', 1)
            ->assertJsonPath('data.summary.locked_registers', 1)
            ->assertJsonPath('data.summary.included_register_days', 2)
            ->assertJsonPath('data.summary.missing_register_days', 1)
            ->assertJsonPath('data.summary.counts.present', 1)
            ->assertJsonPath('data.summary.counts.absent', 1)
            ->assertJsonPath('data.summary.counts.late', 1)
            ->assertJsonPath('data.summary.counts.unrecorded', 1)
            ->assertJsonPath('data.summary.recorded_denominator', 3)
            ->assertJsonPath('data.summary.attended_count', 2)
            ->assertJsonPath('data.summary.attendance_rate', 66.67);
    }

    public function test_report_rejects_ranges_outside_the_term_or_beyond_the_bound(): void
    {
        [$school, $teacher, $session, $term, $classArm] = $this->fixture();
        $token = $this->loginAndSelect($teacher, $school, 'report-password');
        $path = $this->path($school, $session, $term, $classArm);

        $this->withToken($token)->getJson($path.'?from=2025-08-31&to=2025-09-04')->assertNotFound();
        $this->withToken($token)->getJson($path.'?from=2025-09-01&to=2026-09-02')->assertUnprocessable();
    }

    /** @return array{0: School, 1: UserIdentity, 2: string, 3: string, 4: string, 5: list<string>} */
    private function fixture(): array
    {
        $school = School::factory()->create(['name' => 'Fictional Attendance Report School']);
        $teacher = UserIdentity::factory()->withPassword('report-password')->create();
        $teacher->contacts()->update(['canonical_value' => 'attendance-report@example.com', 'verified_at' => now()]);
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $teacher->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', 'teacher')->value('id'), 'assigned_by' => $teacher->id, 'assigned_at' => now()]);

        return TenantContext::runInternal(TenantContext::fromMembership($membership), 'attendance report fixture', function () use ($school, $teacher, $membership): array {
            $session = AcademicSession::create(['school_id' => $school->id, 'name' => 'Report Session', 'code' => 'RPT', 'start_date' => '2025-09-01', 'end_date' => '2026-07-31', 'status' => 'active']);
            $term = AcademicTerm::create(['school_id' => $school->id, 'academic_session_id' => $session->id, 'name' => 'Report Term', 'sequence' => 1, 'start_date' => '2025-09-01', 'end_date' => '2025-12-15', 'status' => 'active']);
            $level = AcademicLevel::create(['school_id' => $school->id, 'name' => 'Report Level', 'code' => 'RL', 'sequence' => 1, 'status' => 'active']);
            $section = AcademicSection::create(['school_id' => $school->id, 'academic_level_id' => $level->id, 'name' => 'Report Section', 'code' => 'RS', 'sequence' => 1, 'status' => 'active']);
            $classArm = AcademicClassArm::create(['school_id' => $school->id, 'academic_level_id' => $level->id, 'academic_section_id' => $section->id, 'name' => 'Report Arm', 'code' => 'RA', 'capacity' => 30, 'status' => 'active']);
            $staff = StaffProfile::create(['school_id' => $school->id, 'school_membership_id' => $membership->id, 'staff_number' => 'RPT-001', 'legal_name' => 'Fictional Report Teacher', 'employment_status' => 'active']);
            ClassTeacherAssignment::create(['school_id' => $school->id, 'academic_session_id' => $session->id, 'academic_term_id' => $term->id, 'academic_class_arm_id' => $classArm->id, 'school_membership_id' => $membership->id, 'staff_profile_id' => $staff->id, 'effective_start' => '2025-09-01', 'status' => 'active', 'assigned_by' => $teacher->id, 'assigned_at' => now()]);
            $students = [];
            foreach (['RPT-STU-001', 'RPT-STU-002'] as $number) {
                $student = Student::create(['school_id' => $school->id, 'student_number' => $number, 'display_name' => 'Fictional Report Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
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

    private function path(School $school, string $session, string $term, string $classArm): string
    {
        return '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms/'.$term.'/class-arms/'.$classArm.'/attendance-report';
    }
}
