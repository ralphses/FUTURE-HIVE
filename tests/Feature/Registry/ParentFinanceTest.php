<?php

declare(strict_types=1);

namespace Tests\Feature\Registry;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Domain\Models\GuardianProfile;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;
use App\Contexts\Registry\Domain\Models\StudentGuardianRelationship;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Academic\AcademicScoreSubmissionTest;

final class ParentFinanceTest extends AcademicScoreSubmissionTest
{
    public function test_verified_guardian_sees_only_safe_balances_and_receipts_for_linked_children(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'parent-finance-admin@example.com', 'parent-finance-admin-password');
        $adminToken = $this->loginAndSelect($admin, $school, 'parent-finance-admin-password');
        $configuration = $this->configuration($school, $adminToken);
        $student = Student::create(['school_id' => $school->id, 'student_number' => 'PFIN-001', 'display_name' => 'Fictional Finance Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        StudentEnrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_level_id' => $configuration['level_id'], 'academic_section_id' => $configuration['section_id'], 'academic_class_arm_id' => $configuration['class_arm_id'], 'start_date' => '2025-09-01', 'status' => 'active']);
        $unrelated = Student::create(['school_id' => $school->id, 'student_number' => 'PFIN-002', 'display_name' => 'Fictional Unrelated Finance Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        $guardian = UserIdentity::factory()->withPassword('parent-finance-password')->create();
        $guardian->contacts()->update(['canonical_value' => 'parent-finance@example.com', 'verified_at' => now()]);
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $guardian->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', 'parent_guardian')->value('id'), 'assigned_by' => $guardian->id, 'assigned_at' => now()]);
        $profile = GuardianProfile::create(['user_id' => $guardian->id, 'display_name' => 'Fictional Finance Guardian', 'metadata' => []]);
        StudentGuardianRelationship::create(['school_id' => $school->id, 'student_id' => $student->id, 'guardian_profile_id' => $profile->id, 'relationship_type' => 'parent', 'status' => 'active', 'verified_at' => now()]);
        StudentGuardianRelationship::create(['school_id' => $school->id, 'student_id' => $unrelated->id, 'guardian_profile_id' => $profile->id, 'relationship_type' => 'parent', 'status' => 'pending']);
        $category = $this->withToken($adminToken)->postJson('/api/v1/schools/'.$school->public_id.'/fee-categories', ['code' => 'PFIN-TUITION', 'name' => 'Fictional Tuition'])->assertCreated()->json('data.category.id');
        $plan = $this->withToken($adminToken)->postJson('/api/v1/schools/'.$school->public_id.'/fee-plans', ['session_id' => $configuration['session'], 'term_id' => $configuration['term'], 'name' => 'Fictional Parent Plan', 'currency' => 'NGN', 'effective_from' => '2025-09-01', 'effective_to' => '2025-12-15', 'lines' => [['category_id' => $category, 'amount_minor_units' => 12500, 'sequence' => 1, 'is_required' => true]]])->assertCreated()->json('data.plan.id');
        $this->withToken($adminToken)->postJson('/api/v1/schools/'.$school->public_id.'/fee-plans/'.$plan.'/activate')->assertOk();
        $assessmentPath = '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/fee-assessments';
        $assessment = $this->withToken($adminToken)->postJson($assessmentPath, ['plan_id' => $plan, 'due_date' => '2025-12-01'])->assertCreated()->json('data.assessment.id');
        $this->withToken($adminToken)->postJson('/api/v1/schools/'.$school->public_id.'/fee-assessments/'.$assessment.'/finalize')->assertOk();
        $token = $this->loginAndSelect($guardian, $school, 'parent-finance-password');

        $this->withToken($token)->getJson('/api/v1/me/parent-fee-balances')
            ->assertOk()
            ->assertJsonPath('data.0.student.id', $student->public_id)
            ->assertJsonPath('data.0.invoices.0.currency', 'NGN')
            ->assertJsonPath('data.0.invoices.0.outstanding_minor_units', 12500)
            ->assertJsonMissing(['Fictional Unrelated Finance Learner', 'parent-finance@example.com']);
        $this->withToken($token)->getJson('/api/v1/me/parent-fee-balances/children/'.$unrelated->public_id)->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/me/parent-receipts')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.receipts', []);
        $this->withToken($token)->getJson('/api/v1/me/parent-receipts/children/'.$student->public_id)->assertOk()->assertJsonPath('data.child.receipts', []);
    }

    public function test_revoked_relationships_cannot_read_parent_finance_views(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'parent-finance-revoke-admin@example.com', 'parent-finance-revoke-admin-password');
        $adminToken = $this->loginAndSelect($admin, $school, 'parent-finance-revoke-admin-password');
        $guardian = UserIdentity::factory()->withPassword('parent-finance-revoke-password')->create();
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $guardian->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', 'parent_guardian')->value('id'), 'assigned_by' => $guardian->id, 'assigned_at' => now()]);
        $profile = GuardianProfile::create(['user_id' => $guardian->id, 'display_name' => 'Fictional Revoked Guardian', 'metadata' => []]);
        $studentPublicId = (string) Str::uuid7();
        $studentId = DB::table('students')->insertGetId(['public_id' => $studentPublicId, 'school_id' => $school->id, 'student_number' => 'PFIN-003', 'display_name' => 'Fictional Revoked Learner', 'admission_date' => '2025-09-01', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('student_guardian_relationships')->insert(['public_id' => (string) Str::uuid7(), 'school_id' => $school->id, 'student_id' => $studentId, 'guardian_profile_id' => $profile->id, 'relationship_type' => 'parent', 'status' => 'revoked', 'verified_at' => now(), 'revoked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $token = $this->loginAndSelect($guardian, $school, 'parent-finance-revoke-password');

        $this->withToken($token)->getJson('/api/v1/me/parent-fee-balances')->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($token)->getJson('/api/v1/me/parent-receipts')->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($token)->getJson('/api/v1/me/parent-fee-balances/children/'.$studentPublicId)->assertNotFound();
    }
}
