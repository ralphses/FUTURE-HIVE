<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Domain\Models\GuardianProfile;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;
use App\Contexts\Registry\Domain\Models\StudentGuardianRelationship;
use Tests\Feature\Academic\AcademicScoreSubmissionTest;

final class FamilyBillingTest extends AcademicScoreSubmissionTest
{
    public function test_finance_staff_can_read_a_derived_family_billing_view(): void
    {
        [$school, $actor] = $this->schoolWithRole('school_admin', 'family-admin@example.com', 'family-password');
        $token = $this->loginAndSelect($actor, $school, 'family-password');
        $configuration = $this->configuration($school, $token);
        [$student] = $this->createBilledStudent($school, $configuration, $token, 'FAM-001');
        $this->createVerifiedGuardian($school, $student, 'guardian@example.com', 'guardian-password');
        $this->createInvoice($school, $configuration, $token);

        $response = $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/family-billing')->assertOk();

        $response->assertJsonPath('data.families.0.students.0.student_number', 'FAM-001')
            ->assertJsonPath('data.families.0.students.0.invoices.0.currency', 'NGN')
            ->assertJsonPath('data.families.0.students.0.invoices.0.total_minor_units', 10000)
            ->assertJsonMissing(['guardian@example.com'])
            ->assertJsonMissing(['password']);
        self::assertDatabaseCount('guardian_profiles', 1);
        self::assertDatabaseCount('student_guardian_relationships', 1);
    }

    public function test_verified_guardian_sees_only_active_linked_students_on_me_route(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'family-admin-two@example.com', 'family-password-two');
        $adminToken = $this->loginAndSelect($admin, $school, 'family-password-two');
        $configuration = $this->configuration($school, $adminToken);
        [$linkedStudent] = $this->createBilledStudent($school, $configuration, $adminToken, 'FAM-002');
        [$unlinkedStudent] = $this->createBilledStudent($school, $configuration, $adminToken, 'FAM-003');
        $guardian = $this->createVerifiedGuardian($school, $linkedStudent, 'verified-guardian@example.com', 'verified-password');
        $this->createInvoice($school, $configuration, $adminToken, $linkedStudent);

        $guardianToken = $this->loginAndSelect($guardian, $school, 'verified-password');
        $this->withToken($guardianToken)->getJson('/api/v1/me/family-billing')
            ->assertOk()
            ->assertJsonPath('data.families.0.students.0.student_number', 'FAM-002')
            ->assertJsonMissing(['FAM-003']);

        StudentGuardianRelationship::query()->where('student_id', $linkedStudent->id)->update(['status' => 'revoked', 'revoked_at' => now()]);

        $this->withToken($guardianToken)->getJson('/api/v1/me/family-billing')
            ->assertOk()
            ->assertJsonCount(0, 'data.families');
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array{0: Student, 1: StudentEnrollment}
     */
    private function createBilledStudent(object $school, array $configuration, string $token, string $studentNumber): array
    {
        $student = Student::create(['school_id' => $school->id, 'student_number' => $studentNumber, 'display_name' => 'Fictional '.$studentNumber, 'admission_date' => '2025-09-01', 'status' => 'active']);
        $enrollment = StudentEnrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_level_id' => $configuration['level_id'], 'academic_section_id' => $configuration['section_id'], 'academic_class_arm_id' => $configuration['class_arm_id'], 'start_date' => '2025-09-01', 'status' => 'active']);

        return [$student, $enrollment];
    }

    /** @param array<string, mixed> $configuration */
    private function createInvoice(object $school, array $configuration, string $token, ?Student $student = null): string
    {
        $student ??= Student::query()->latest('id')->firstOrFail();
        $categoryResponse = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/fee-categories', ['code' => 'FAM-'.$student->student_number, 'name' => 'Family Tuition '.$student->student_number]);
        $categoryResponse->assertCreated();
        $category = $categoryResponse->json('data.category.id');
        $plan = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/fee-plans', ['session_id' => $configuration['session'], 'term_id' => $configuration['term'], 'name' => 'Family Plan '.$student->student_number, 'currency' => 'NGN', 'effective_from' => '2025-09-01', 'effective_to' => '2025-12-15', 'lines' => [['category_id' => $category, 'amount_minor_units' => 10000, 'sequence' => 1, 'is_required' => true]]])->assertCreated()->json('data.plan.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/fee-plans/'.$plan.'/activate')->assertOk();
        $assessmentPath = '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/fee-assessments';

        return $this->withToken($token)->postJson($assessmentPath, ['plan_id' => $plan, 'due_date' => '2025-12-01'])->assertCreated()->json('data.assessment.id');
    }

    private function createVerifiedGuardian(object $school, Student $student, string $email, string $password): UserIdentity
    {
        $guardian = UserIdentity::factory()->withPassword($password)->create();
        $guardian->contacts()->update(['canonical_value' => $email, 'verified_at' => now()]);
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $guardian->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', 'parent_guardian')->value('id'), 'assigned_by' => $guardian->id, 'assigned_at' => now()]);
        $profile = GuardianProfile::create(['user_id' => $guardian->id, 'display_name' => 'Fictional Guardian', 'metadata' => []]);
        StudentGuardianRelationship::create(['school_id' => $school->id, 'student_id' => $student->id, 'guardian_profile_id' => $profile->id, 'relationship_type' => 'parent', 'status' => 'active', 'verified_at' => now()]);

        return $guardian;
    }
}
