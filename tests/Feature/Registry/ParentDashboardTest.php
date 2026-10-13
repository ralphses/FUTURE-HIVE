<?php

declare(strict_types=1);

namespace Tests\Feature\Registry;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Domain\Models\GuardianProfile;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentGuardianRelationship;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Academic\AcademicScoreSubmissionTest;

final class ParentDashboardTest extends AcademicScoreSubmissionTest
{
    public function test_verified_guardian_sees_only_active_verified_children(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'dashboard-admin@example.com', 'dashboard-admin-password');
        $adminToken = $this->loginAndSelect($admin, $school, 'dashboard-admin-password');
        $this->withToken($adminToken)->getJson('/api/v1/auth/context')->assertOk();
        $guardian = UserIdentity::factory()->withPassword('dashboard-password')->create();
        $guardian->contacts()->update(['verified_at' => now()]);
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $guardian->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', 'parent_guardian')->value('id'), 'assigned_by' => $guardian->id, 'assigned_at' => now()]);
        $profile = GuardianProfile::create(['user_id' => $guardian->id, 'display_name' => 'Fictional Dashboard Guardian', 'metadata' => []]);
        $visible = Student::create(['school_id' => $school->id, 'student_number' => 'POR-001', 'display_name' => 'Fictional Visible Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        $pending = Student::create(['school_id' => $school->id, 'student_number' => 'POR-002', 'display_name' => 'Fictional Pending Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        StudentGuardianRelationship::create(['school_id' => $school->id, 'student_id' => $visible->id, 'guardian_profile_id' => $profile->id, 'relationship_type' => 'parent', 'status' => 'active', 'verified_at' => now()]);
        StudentGuardianRelationship::create(['school_id' => $school->id, 'student_id' => $pending->id, 'guardian_profile_id' => $profile->id, 'relationship_type' => 'parent', 'status' => 'pending']);
        $token = $this->loginAndSelect($guardian, $school, 'dashboard-password');

        $this->withToken($token)->getJson('/api/v1/me/parent-dashboard')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student_id', $visible->public_id)
            ->assertJsonMissing(['Fictional Pending Learner'])
            ->assertJsonMissing(['dashboard-admin@example.com']);
        $this->withToken($token)->getJson('/api/v1/me/parent-dashboard/children/'.$pending->public_id)->assertNotFound();
    }

    public function test_parent_dashboard_does_not_create_records_or_accept_unrelated_students(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'dashboard-owner@example.com', 'dashboard-owner-password');
        $adminToken = $this->loginAndSelect($admin, $school, 'dashboard-owner-password');
        $this->withToken($adminToken)->getJson('/api/v1/auth/context')->assertOk();
        $guardian = UserIdentity::factory()->withPassword('dashboard-owner-password')->create();
        $guardian->contacts()->update(['verified_at' => now()]);
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $guardian->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', 'parent_guardian')->value('id'), 'assigned_by' => $guardian->id, 'assigned_at' => now()]);
        $profile = GuardianProfile::create(['user_id' => $guardian->id, 'display_name' => 'Fictional Owner Guardian', 'metadata' => []]);
        $student = Student::create(['school_id' => $school->id, 'student_number' => 'POR-003', 'display_name' => 'Fictional Owner Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        StudentGuardianRelationship::create(['school_id' => $school->id, 'student_id' => $student->id, 'guardian_profile_id' => $profile->id, 'relationship_type' => 'parent', 'status' => 'active', 'verified_at' => now()]);
        $unrelated = Student::create(['school_id' => $school->id, 'student_number' => 'POR-004', 'display_name' => 'Fictional Unrelated Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        $token = $this->loginAndSelect($guardian, $school, 'dashboard-owner-password');
        $relationships = DB::table('student_guardian_relationships')->count();

        $this->withToken($token)->getJson('/api/v1/me/parent-dashboard/children/'.$unrelated->public_id)->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/me/parent-dashboard')->assertOk();

        self::assertSame($relationships, DB::table('student_guardian_relationships')->count());
    }
}
