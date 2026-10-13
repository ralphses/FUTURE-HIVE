<?php

declare(strict_types=1);

namespace Tests\Feature\Registry;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Domain\Models\Student;
use Tests\Feature\Academic\AcademicScoreSubmissionTest;

final class StudentDashboardTest extends AcademicScoreSubmissionTest
{
    public function test_student_sees_only_own_school_dashboard(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'student-dashboard-admin@example.com', 'student-dashboard-admin-password');
        $adminToken = $this->loginAndSelect($admin, $school, 'student-dashboard-admin-password');
        $this->withToken($adminToken)->getJson('/api/v1/auth/context')->assertOk();

        [$studentIdentity, $student] = $this->student($school, 'student-dashboard@example.com', 'student-dashboard-password', 'POR-002-001', 'Fictional Dashboard Student');
        Student::create(['school_id' => $school->id, 'student_number' => 'POR-002-002', 'display_name' => 'Fictional Peer', 'admission_date' => '2025-09-01', 'status' => 'active']);
        $token = $this->loginAndSelect($studentIdentity, $school, 'student-dashboard-password');

        $this->withToken($token)->getJson('/api/v1/me/student-dashboard')
            ->assertOk()
            ->assertJsonPath('data.students.0.student_id', $student->public_id)
            ->assertJsonPath('data.students.0.display_name', 'Fictional Dashboard Student')
            ->assertJsonMissing(['Fictional Peer'])
            ->assertJsonMissing(['student-dashboard-admin@example.com']);
    }

    public function test_inactive_student_membership_cannot_read_dashboard(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'student-dashboard-state@example.com', 'student-dashboard-state-password');
        $adminToken = $this->loginAndSelect($admin, $school, 'student-dashboard-state-password');
        $this->withToken($adminToken)->getJson('/api/v1/auth/context')->assertOk();
        [$studentIdentity] = $this->student($school, 'student-dashboard-state-student@example.com', 'student-dashboard-state-student-password', 'POR-002-003', 'Fictional Inactive Student');
        SchoolMembership::query()->where('user_id', $studentIdentity->id)->update(['status' => 'revoked']);
        $token = $this->postJson('/api/v1/auth/login', ['login' => 'student-dashboard-state-student@example.com', 'password' => 'student-dashboard-state-student-password'])->assertOk()->json('data.access_token');

        $this->withToken($token)->getJson('/api/v1/me/student-dashboard')->assertNotFound();
    }

    /** @return array{0: UserIdentity, 1: Student} */
    private function student(object $school, string $email, string $password, string $number, string $name): array
    {
        $identity = UserIdentity::factory()->withPassword($password)->create();
        $identity->contacts()->update(['canonical_value' => $email, 'verified_at' => now()]);
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $identity->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', 'student')->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);
        $student = Student::create(['school_id' => $school->id, 'user_id' => $identity->id, 'student_number' => $number, 'display_name' => $name, 'admission_date' => '2025-09-01', 'status' => 'active']);

        return [$identity, $student];
    }
}
