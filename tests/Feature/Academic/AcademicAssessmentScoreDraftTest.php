<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Contexts\Academic\Domain\Models\AcademicAssessmentInstance;
use App\Contexts\Academic\Domain\Models\AcademicAssessmentPolicyComponent;
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
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AcademicAssessmentScoreDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_roster_can_save_single_and_bulk_draft_scores(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'score-admin@example.com', 'score-password');
        $token = $this->loginAndSelect($admin, $school, 'score-password');
        $configuration = $this->configuration($school, $token);
        $student = Student::create(['school_id' => $school->id, 'student_number' => 'SCORE-001', 'display_name' => 'Fictional Score Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        StudentEnrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_level_id' => $configuration['level_id'], 'academic_section_id' => $configuration['section_id'], 'academic_class_arm_id' => $configuration['class_arm_id'], 'start_date' => '2025-09-01', 'status' => 'active']);
        $base = $this->path($school, $configuration);

        $this->withToken($token)->getJson($base.'/scores')->assertOk()->assertJsonPath('data.items.0.entries.0.status', 'missing');
        $this->withToken($token)->putJson($base.'/scores', ['entries' => [['student_id' => $student->public_id, 'component_id' => $configuration['component_id'], 'status' => 'entered', 'score' => 0, 'expected_version' => 0]]])->assertOk()->assertJsonPath('data.items.0.score', 0);
        $this->withToken($token)->putJson($base.'/scores/'.$student->public_id.'/'.$configuration['component_id'], ['status' => 'absent', 'score' => 1, 'expected_version' => 1])->assertUnprocessable();
        $this->withToken($token)->putJson($base.'/scores', ['entries' => [['student_id' => $student->public_id, 'component_id' => $configuration['component_id'], 'status' => 'entered', 'score' => 21, 'expected_version' => 1]]])->assertUnprocessable();
        $this->assertDatabaseHas('academic_assessment_score_entries', ['student_id' => $student->id, 'status' => 'entered', 'score' => 0]);
        self::assertSame(1, AcademicAssessmentInstance::query()->count());
    }

    public function test_closed_instances_reject_score_changes(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'score-closed@example.com', 'score-closed-password');
        $token = $this->loginAndSelect($admin, $school, 'score-closed-password');
        $configuration = $this->configuration($school, $token);
        $student = Student::create(['school_id' => $school->id, 'student_number' => 'SCORE-002', 'display_name' => 'Fictional Closed Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        StudentEnrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_level_id' => $configuration['level_id'], 'academic_section_id' => $configuration['section_id'], 'academic_class_arm_id' => $configuration['class_arm_id'], 'start_date' => '2025-09-01', 'status' => 'active']);
        $base = $this->path($school, $configuration);
        $this->withToken($token)->postJson($base.'/close')->assertOk();
        $this->withToken($token)->putJson($base.'/scores', ['entries' => [['student_id' => $student->public_id, 'component_id' => $configuration['component_id'], 'status' => 'entered', 'score' => 10, 'expected_version' => 0]]])->assertUnprocessable();
    }

    public function test_scores_are_rounded_half_up_and_checked_after_rounding(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'score-rounding@example.com', 'score-rounding-password');
        $token = $this->loginAndSelect($admin, $school, 'score-rounding-password');
        $configuration = $this->configuration($school, $token);
        $student = Student::create(['school_id' => $school->id, 'student_number' => 'SCORE-003', 'display_name' => 'Fictional Rounding Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        StudentEnrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_level_id' => $configuration['level_id'], 'academic_section_id' => $configuration['section_id'], 'academic_class_arm_id' => $configuration['class_arm_id'], 'start_date' => '2025-09-01', 'status' => 'active']);
        $base = $this->path($school, $configuration);

        $this->withToken($token)->putJson($base.'/scores', ['entries' => [['student_id' => $student->public_id, 'component_id' => $configuration['component_id'], 'status' => 'entered', 'score' => '10.005', 'expected_version' => 0]]])->assertOk()->assertJsonPath('data.items.0.score', 10.01);
        $this->assertDatabaseHas('academic_assessment_score_entries', ['student_id' => $student->id, 'status' => 'entered', 'score' => '10.01']);
        $this->withToken($token)->putJson($base.'/scores', ['entries' => [['student_id' => $student->public_id, 'component_id' => $configuration['component_id'], 'status' => 'entered', 'score' => '20.005', 'expected_version' => 1]]])->assertUnprocessable();
        $this->assertDatabaseHas('academic_assessment_score_entries', ['student_id' => $student->id, 'status' => 'entered', 'score' => '10.01']);
    }

    /** @return array<string, mixed> */
    private function configuration(School $school, string $token): array
    {
        $base = '/api/v1/schools/'.$school->public_id;
        $session = $this->withToken($token)->postJson($base.'/academic-sessions', ['name' => 'Score Session', 'code' => 'SCS', 'start_date' => '2025-09-01', 'end_date' => '2026-07-31'])->assertCreated();
        $sessionId = $session->json('data.id');
        $term = $this->withToken($token)->postJson($base.'/academic-sessions/'.$sessionId.'/terms', ['name' => 'Score Term', 'sequence' => 1, 'start_date' => '2025-09-01', 'end_date' => '2025-12-15'])->assertCreated();
        $termId = $term->json('data.id');
        $this->withToken($token)->postJson($base.'/academic-sessions/'.$sessionId.'/activate', ['reason' => 'Fictional setup activation'])->assertOk();
        $this->withToken($token)->postJson($base.'/academic-sessions/'.$sessionId.'/terms/'.$termId.'/activate', ['reason' => 'Fictional setup activation'])->assertOk();
        $level = $this->withToken($token)->postJson($base.'/academic-levels', ['name' => 'Score Level', 'code' => 'SCL', 'sequence' => 1])->assertCreated();
        $levelId = $level->json('data.id');
        $section = $this->withToken($token)->postJson($base.'/academic-levels/'.$levelId.'/sections', ['name' => 'Score Section', 'code' => 'SCS', 'sequence' => 1])->assertCreated();
        $sectionId = $section->json('data.id');
        $classArm = $this->withToken($token)->postJson($base.'/academic-levels/'.$levelId.'/sections/'.$sectionId.'/class-arms', ['name' => 'Score Arm', 'code' => 'SCA', 'capacity' => 30])->assertCreated();
        $classArmId = $classArm->json('data.id');
        $subject = $this->withToken($token)->postJson($base.'/subjects', ['name' => 'Score Subject', 'code' => 'SCSUB'])->assertCreated()->json('data.id');
        $offerings = $base.'/academic-sessions/'.$sessionId.'/terms/'.$termId.'/subject-offerings';
        $offering = $this->withToken($token)->postJson($offerings, ['class_arm_id' => $classArmId, 'subject_id' => $subject])->assertCreated()->json('data.id');
        $this->withToken($token)->putJson($offerings.'/'.$offering.'/assessment-scheme', ['name' => 'Score Scheme', 'total_marks' => 100, 'components' => [['name' => 'CA', 'category' => 'ca', 'max_marks' => 20, 'sequence' => 1], ['name' => 'Exam', 'category' => 'exam', 'max_marks' => 80, 'sequence' => 2]]])->assertOk();
        $policies = $offerings.'/'.$offering.'/assessment-policies';
        $policy = $this->withToken($token)->postJson($policies, ['effective_start' => '2025-09-01'])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson($policies.'/'.$policy.'/activate')->assertOk();
        $scales = $policies.'/'.$policy.'/grading-scales';
        $scale = $this->withToken($token)->postJson($scales, ['name' => 'Score Scale', 'effective_start' => '2025-09-01', 'bands' => [['grade' => 'F', 'label' => 'Fail', 'minimum_percentage' => 0, 'maximum_percentage' => 49.99, 'is_passing' => false, 'sequence' => 1], ['grade' => 'P', 'label' => 'Pass', 'minimum_percentage' => 50, 'maximum_percentage' => 100, 'is_passing' => true, 'sequence' => 2]]])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson($scales.'/'.$scale.'/activate')->assertOk();
        $instances = $offerings.'/'.$offering.'/assessment-instances';
        $instance = $this->withToken($token)->postJson($instances, ['policy_id' => $policy, 'grading_scale_id' => $scale, 'title' => 'Score Assessment', 'assessment_date' => '2025-10-10'])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson($instances.'/'.$instance.'/open')->assertOk();
        $access = $this->pathForAccess($school, $sessionId, $termId, $offering, $instance);
        $this->withToken($token)->putJson($access, ['opens_at' => '2025-10-01T00:00:00+00:00', 'closes_at' => '2025-11-30T23:59:59+00:00'])->assertOk();
        $this->withToken($token)->postJson($access.'/activate')->assertOk();
        $this->travelTo('2025-10-10 10:00:00+00:00');
        $componentId = AcademicAssessmentPolicyComponent::query()->whereHas('version', fn ($query) => $query->where('public_id', $policy))->where('name', 'CA')->value('public_id');

        return ['session' => $sessionId, 'session_id' => AcademicSession::query()->where('public_id', $sessionId)->value('id'), 'term' => $termId, 'term_id' => AcademicTerm::query()->where('public_id', $termId)->value('id'), 'level_id' => AcademicLevel::query()->where('public_id', $levelId)->value('id'), 'section_id' => AcademicSection::query()->where('public_id', $sectionId)->value('id'), 'class_arm_id' => AcademicClassArm::query()->where('public_id', $classArmId)->value('id'), 'offering' => $offering, 'instance' => $instance, 'component_id' => $componentId];
    }

    private function pathForAccess(School $school, string $session, string $term, string $offering, string $instance): string
    {
        return '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms/'.$term.'/subject-offerings/'.$offering.'/assessment-instances/'.$instance.'/access';
    }

    /** @param array<string, mixed> $configuration */
    private function path(School $school, array $configuration): string
    {
        return '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/subject-offerings/'.$configuration['offering'].'/assessment-instances/'.$configuration['instance'];
    }

    /** @return array{0: School, 1: UserIdentity} */
    private function schoolWithRole(string $roleKey, string $email, string $password): array
    {
        $school = School::factory()->create(['name' => 'Fictional Score School']);
        $identity = UserIdentity::factory()->withPassword($password)->create();
        $identity->contacts()->update(['canonical_value' => $email, 'verified_at' => now()]);
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $identity->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', $roleKey)->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);

        return [$school, $identity];
    }

    private function loginAndSelect(UserIdentity $identity, School $school, string $password): string
    {
        $token = $this->postJson('/api/v1/auth/login', ['login' => $identity->contacts()->firstOrFail()->canonical_value, 'password' => $password])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return $token;
    }
}
