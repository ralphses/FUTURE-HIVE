<?php

declare(strict_types=1);

namespace Tests\Feature\Registry;

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
use App\Contexts\Registry\Domain\Models\GuardianProfile;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;
use App\Contexts\Registry\Domain\Models\StudentGuardianRelationship;
use App\Support\Tenancy\TenantContext;
use Tests\Feature\Academic\AcademicScoreSubmissionTest;

final class ParentAttendanceTest extends AcademicScoreSubmissionTest
{
    public function test_verified_guardian_sees_child_attendance_using_published_registers_only(): void
    {
        [$school, $guardian, $session, $term, $classArm, $student] = $this->fixture();
        $token = $this->loginAndSelect($guardian, $school, 'parent-attendance-password');
        $query = '?session='.$session.'&term='.$term.'&from=2025-09-02&to=2025-09-04';

        $this->withToken($token)->getJson('/api/v1/me/parent-attendance'.$query)
            ->assertOk()
            ->assertJsonPath('data.0.student.id', $student->public_id)
            ->assertJsonPath('data.0.summary.submitted_registers', 1)
            ->assertJsonPath('data.0.summary.locked_registers', 1)
            ->assertJsonPath('data.0.summary.included_register_days', 2)
            ->assertJsonPath('data.0.summary.missing_register_days', 1)
            ->assertJsonPath('data.0.summary.counts.present', 1)
            ->assertJsonPath('data.0.summary.counts.absent', 1)
            ->assertJsonPath('data.0.summary.counts.late', 0)
            ->assertJsonPath('data.0.summary.recorded_denominator', 2)
            ->assertJsonPath('data.0.summary.attended_count', 1)
            ->assertJsonPath('data.0.summary.attendance_rate', 50)
            ->assertJsonMissing(['parent-attendance-password']);

        $this->withToken($token)->getJson('/api/v1/me/parent-attendance/children/'.$student->public_id.$query)
            ->assertOk()
            ->assertJsonPath('data.attendance.student.id', $student->public_id);
        self::assertSame(3, AcademicAttendanceRegister::query()->count());
    }

    public function test_unrelated_child_is_not_visible(): void
    {
        [$school, $guardian, $session, $term, $classArm, $student] = $this->fixture();
        $membership = SchoolMembership::query()->where('school_id', $school->id)->where('user_id', $guardian->id)->firstOrFail();
        $unrelated = TenantContext::runInternal(
            TenantContext::fromMembership($membership),
            'parent attendance unrelated fixture',
            fn (): Student => Student::create(['school_id' => $school->id, 'student_number' => 'POR-ATT-002', 'display_name' => 'Fictional Unrelated Attendance Learner', 'admission_date' => '2025-09-01', 'status' => 'active']),
        );
        $token = $this->loginAndSelect($guardian, $school, 'parent-attendance-password');
        $query = '?session='.$session.'&term='.$term.'&from=2025-09-02&to=2025-09-04';

        $this->withToken($token)->getJson('/api/v1/me/parent-attendance/children/'.$unrelated->public_id.$query)->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/me/parent-attendance'.$query)->assertJsonMissing(['Fictional Unrelated Attendance Learner']);
        self::assertNotSame($student->public_id, $unrelated->public_id);
    }

    /** @return array{0: School, 1: UserIdentity, 2: string, 3: string, 4: string, 5: Student} */
    private function fixture(): array
    {
        $school = School::factory()->create(['name' => 'Fictional Parent Attendance School']);
        $guardian = UserIdentity::factory()->withPassword('parent-attendance-password')->create();
        $guardian->contacts()->update(['canonical_value' => 'parent-attendance@example.com', 'verified_at' => now()]);
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $guardian->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', 'parent_guardian')->value('id'), 'assigned_by' => $guardian->id, 'assigned_at' => now()]);
        $profile = GuardianProfile::create(['user_id' => $guardian->id, 'display_name' => 'Fictional Attendance Guardian', 'metadata' => []]);

        return TenantContext::runInternal(TenantContext::fromMembership($membership), 'parent attendance fixture', function () use ($school, $guardian, $profile): array {
            $session = AcademicSession::create(['school_id' => $school->id, 'name' => 'Parent Attendance Session', 'code' => 'PAT', 'start_date' => '2025-09-01', 'end_date' => '2026-07-31', 'status' => 'active']);
            $term = AcademicTerm::create(['school_id' => $school->id, 'academic_session_id' => $session->id, 'name' => 'Parent Attendance Term', 'sequence' => 1, 'start_date' => '2025-09-01', 'end_date' => '2025-12-15', 'status' => 'active']);
            $level = AcademicLevel::create(['school_id' => $school->id, 'name' => 'Parent Attendance Level', 'code' => 'PAL', 'sequence' => 1, 'status' => 'active']);
            $section = AcademicSection::create(['school_id' => $school->id, 'academic_level_id' => $level->id, 'name' => 'Parent Attendance Section', 'code' => 'PAS', 'sequence' => 1, 'status' => 'active']);
            $classArm = AcademicClassArm::create(['school_id' => $school->id, 'academic_level_id' => $level->id, 'academic_section_id' => $section->id, 'name' => 'Parent Attendance Arm', 'code' => 'PAA', 'capacity' => 30, 'status' => 'active']);
            $student = Student::create(['school_id' => $school->id, 'student_number' => 'POR-ATT-001', 'display_name' => 'Fictional Attendance Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
            StudentEnrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'academic_session_id' => $session->id, 'academic_term_id' => $term->id, 'academic_level_id' => $level->id, 'academic_section_id' => $section->id, 'academic_class_arm_id' => $classArm->id, 'start_date' => '2025-09-01', 'status' => 'active']);
            StudentGuardianRelationship::create(['school_id' => $school->id, 'student_id' => $student->id, 'guardian_profile_id' => $profile->id, 'relationship_type' => 'parent', 'status' => 'active', 'verified_at' => now()]);
            foreach ([['2025-09-02', 'submitted', 'present'], ['2025-09-03', 'locked', 'absent'], ['2025-09-04', 'draft', 'late']] as [$date, $registerStatus, $entryStatus]) {
                $register = AcademicAttendanceRegister::create(['school_id' => $school->id, 'academic_session_id' => $session->id, 'academic_term_id' => $term->id, 'academic_class_arm_id' => $classArm->id, 'attendance_date' => $date, 'status' => $registerStatus, 'created_by' => $guardian->id]);
                AcademicAttendanceEntry::create(['school_id' => $school->id, 'attendance_register_id' => $register->id, 'student_id' => $student->id, 'status' => $entryStatus]);
            }

            return [$school, $guardian, (string) $session->public_id, (string) $term->public_id, (string) $classArm->public_id, $student];
        });
    }

    protected function loginAndSelect(UserIdentity $identity, School $school, string $password): string
    {
        $token = $this->postJson('/api/v1/auth/login', ['login' => $identity->contacts()->firstOrFail()->canonical_value, 'password' => $password])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return $token;
    }
}
