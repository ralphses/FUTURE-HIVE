<?php

declare(strict_types=1);

namespace Tests\Feature\Registry;

use App\Contexts\Academic\Domain\Models\AcademicReportCardTemplate;
use App\Contexts\Academic\Domain\Models\AcademicResultGate;
use App\Contexts\Academic\Domain\Models\AcademicResultRun;
use App\Contexts\Academic\Domain\Models\AcademicResultSnapshot;
use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Domain\Models\GuardianProfile;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentGuardianRelationship;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Academic\AcademicScoreSubmissionTest;

final class ParentReportCardTest extends AcademicScoreSubmissionTest
{
    public function test_verified_guardian_sees_only_the_current_published_report_card_with_active_matching_template(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'por004-admin@example.com', 'por004-admin-password');
        $adminToken = $this->loginAndSelect($admin, $school, 'por004-admin-password');
        $configuration = $this->configuration($school, $adminToken);
        $guardian = UserIdentity::factory()->withPassword('por004-guardian-password')->create();
        $guardian->contacts()->update(['verified_at' => now()]);
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $guardian->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', 'parent_guardian')->value('id'), 'assigned_by' => $guardian->id, 'assigned_at' => now()]);
        $profile = GuardianProfile::create(['user_id' => $guardian->id, 'display_name' => 'Fictional Report Card Guardian', 'metadata' => []]);
        $student = Student::create(['school_id' => $school->id, 'student_number' => 'POR-004-001', 'display_name' => 'Fictional Report Card Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        $unrelated = Student::create(['school_id' => $school->id, 'student_number' => 'POR-004-002', 'display_name' => 'Fictional Unrelated Report Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        StudentGuardianRelationship::create(['school_id' => $school->id, 'student_id' => $student->id, 'guardian_profile_id' => $profile->id, 'relationship_type' => 'parent', 'status' => 'active', 'verified_at' => now()]);
        $snapshot = $this->publishedReportSnapshot($school->id, $configuration, $admin);
        AcademicReportCardTemplate::create(['school_id' => $school->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_result_snapshot_id' => $snapshot->id, 'version' => 1, 'name' => 'Fictional Active Report', 'status' => 'active', 'created_by' => $admin->id, 'activated_by' => $admin->id, 'activated_at' => now()]);
        $template = AcademicReportCardTemplate::query()->latest('id')->firstOrFail();
        $template->sections()->create(['section_key' => 'student_identity', 'label' => 'Student identity', 'sequence' => 1, 'is_visible' => true])->fields()->create(['field_key' => 'student_name', 'label' => 'Student name', 'field_type' => 'text', 'sequence' => 1, 'is_visible' => true]);
        StudentGuardianRelationship::create(['school_id' => $school->id, 'student_id' => $unrelated->id, 'guardian_profile_id' => $profile->id, 'relationship_type' => 'parent', 'status' => 'pending']);
        $token = $this->loginAndSelect($guardian, $school, 'por004-guardian-password');

        $this->withToken($token)->getJson('/api/v1/me/parent-report-cards')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.availability.status', 'available')->assertJsonPath('data.0.sections.0.fields.0.value', 'Fictional Report Card Learner')->assertJsonMissing(['Fictional Unrelated Report Learner']);
        $this->withToken($token)->getJson('/api/v1/me/parent-report-cards/children/'.$unrelated->public_id)->assertNotFound();
    }

    public function test_missing_matching_template_is_safe_and_read_only(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'por004-safe-admin@example.com', 'por004-safe-admin-password');
        $adminToken = $this->loginAndSelect($admin, $school, 'por004-safe-admin-password');
        $configuration = $this->configuration($school, $adminToken);
        $guardian = UserIdentity::factory()->withPassword('por004-safe-password')->create();
        $guardian->contacts()->update(['verified_at' => now()]);
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $guardian->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', 'parent_guardian')->value('id'), 'assigned_by' => $guardian->id, 'assigned_at' => now()]);
        $profile = GuardianProfile::create(['user_id' => $guardian->id, 'display_name' => 'Fictional Safe Guardian', 'metadata' => []]);
        $student = Student::create(['school_id' => $school->id, 'student_number' => 'POR-004-003', 'display_name' => 'Fictional Safe Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        StudentGuardianRelationship::create(['school_id' => $school->id, 'student_id' => $student->id, 'guardian_profile_id' => $profile->id, 'relationship_type' => 'parent', 'status' => 'active', 'verified_at' => now()]);
        $this->publishedReportSnapshot($school->id, $configuration, $admin);
        $token = $this->loginAndSelect($guardian, $school, 'por004-safe-password');
        $relationships = DB::table('student_guardian_relationships')->count();

        $this->withToken($token)->getJson('/api/v1/me/parent-report-cards/children/'.$student->public_id)->assertOk()->assertJsonPath('data.report_card.availability.status', 'template_unavailable')->assertJsonPath('data.report_card.sections', []);
        self::assertSame($relationships, DB::table('student_guardian_relationships')->count());
    }

    /** @param array<string, mixed> $configuration */
    private function publishedReportSnapshot(int $schoolId, array $configuration, UserIdentity $publisher): AcademicResultSnapshot
    {
        $run = AcademicResultRun::create(['school_id' => $schoolId, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'status' => 'completed', 'source_fingerprint' => hash('sha256', 'por004-report-source-'.$schoolId), 'computed_count' => 1, 'not_computable_count' => 0, 'created_by' => $publisher->id]);
        $gate = AcademicResultGate::create(['school_id' => $schoolId, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_result_run_id' => $run->id, 'status' => 'published', 'published_by' => $publisher->id, 'published_at' => now()]);

        return AcademicResultSnapshot::create(['school_id' => $schoolId, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_result_gate_id' => $gate->id, 'academic_result_run_id' => $run->id, 'source_fingerprint' => $run->source_fingerprint, 'snapshot_version' => 1, 'status' => 'published', 'published_by' => $publisher->id, 'published_at' => now()]);
    }
}
