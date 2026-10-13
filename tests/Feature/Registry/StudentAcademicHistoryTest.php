<?php

declare(strict_types=1);

namespace Tests\Feature\Registry;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Domain\Models\Student;
use App\Support\Tenancy\TenantContext;
use Tests\Feature\Academic\AcademicScoreSubmissionTest;

final class StudentAcademicHistoryTest extends AcademicScoreSubmissionTest
{
    public function test_student_sees_only_own_published_history_projection(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'history-admin@example.com', 'history-admin-password');
        $this->loginAndSelect($admin, $school, 'history-admin-password');
        [$identity, $student] = $this->student($school, 'history-student@example.com', 'history-password', 'POR-008-001', 'Fictional History Student');
        TenantContext::runInternal(
            TenantContext::fromMembership(SchoolMembership::query()->where('school_id', $school->id)->where('user_id', $admin->id)->firstOrFail()),
            'student academic history peer fixture',
            fn (): Student => Student::create(['school_id' => $school->id, 'student_number' => 'POR-008-002', 'display_name' => 'Fictional History Peer', 'admission_date' => '2025-09-01', 'status' => 'active']),
        );
        $token = $this->loginAndSelect($identity, $school, 'history-password');

        $this->withToken($token)->getJson('/api/v1/me/student-academic-history')->assertOk()->assertJsonPath('data.schools.0.student.id', $student->public_id)->assertJsonMissing(['Fictional History Peer'])->assertJsonMissing(['history-admin@example.com']);
        $this->withToken($token)->getJson('/api/v1/me/student-academic-history/schools/'.$school->public_id)->assertOk()->assertJsonPath('data.school.student.id', $student->public_id);
    }

    public function test_inactive_student_membership_and_guessed_school_are_denied(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'history-state-admin@example.com', 'history-state-admin-password');
        $this->loginAndSelect($admin, $school, 'history-state-admin-password');
        [$identity] = $this->student($school, 'history-state-student@example.com', 'history-state-password', 'POR-008-003', 'Fictional Inactive History Student');
        SchoolMembership::query()->where('user_id', $identity->id)->update(['status' => 'revoked', 'revoked_at' => now()]);
        $token = $this->postJson('/api/v1/auth/login', ['login' => 'history-state-student@example.com', 'password' => 'history-state-password'])->assertOk()->json('data.access_token');

        $this->withToken($token)->getJson('/api/v1/me/student-academic-history')->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/me/student-academic-history/schools/'.$school->public_id)->assertNotFound();
    }

    /** @return array{0: UserIdentity, 1: Student} */
    private function student(object $school, string $email, string $password, string $number, string $name): array
    {
        $identity = UserIdentity::factory()->withPassword($password)->create();
        $identity->contacts()->update(['canonical_value' => $email, 'verified_at' => now()]);
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $identity->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', 'student')->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);
        $student = TenantContext::runInternal(
            TenantContext::fromMembership($membership),
            'student academic history fixture',
            fn (): Student => Student::create(['school_id' => $school->id, 'user_id' => $identity->id, 'student_number' => $number, 'display_name' => $name, 'admission_date' => '2025-09-01', 'status' => 'active']),
        );

        return [$identity, $student];
    }
}
