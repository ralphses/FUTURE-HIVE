<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

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

class AcademicScoreSubmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_roster_can_be_submitted_idempotently_and_then_cannot_be_edited(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'submission-admin@example.com', 'submission-password');
        $token = $this->loginAndSelect($admin, $school, 'submission-password');
        $configuration = $this->configuration($school, $token);
        $student = Student::create(['school_id' => $school->id, 'student_number' => 'SUBMIT-001', 'display_name' => 'Fictional Submission Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        StudentEnrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_level_id' => $configuration['level_id'], 'academic_section_id' => $configuration['section_id'], 'academic_class_arm_id' => $configuration['class_arm_id'], 'start_date' => '2025-09-01', 'status' => 'active']);
        $base = $this->path($school, $configuration);
        $entries = [
            ['student_id' => $student->public_id, 'component_id' => $configuration['component_ids'][0], 'status' => 'entered', 'score' => 0, 'expected_version' => 0],
            ['student_id' => $student->public_id, 'component_id' => $configuration['component_ids'][1], 'status' => 'absent', 'expected_version' => 0],
        ];

        $this->withToken($token)->putJson($base.'/scores', ['entries' => $entries])->assertOk();
        $response = $this->withToken($token)->postJson($base.'/scores/submit')->assertOk();
        $response->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.entry_count', 2);
        $this->withToken($token)->postJson($base.'/scores/submit')->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->withToken($token)->getJson($base.'/scores/submission')->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->withToken($token)->putJson($base.'/scores', ['entries' => [$entries[0]]])->assertUnprocessable();
    }

    public function test_missing_score_state_blocks_submission(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'submission-missing@example.com', 'submission-missing-password');
        $token = $this->loginAndSelect($admin, $school, 'submission-missing-password');
        $configuration = $this->configuration($school, $token);
        $student = Student::create(['school_id' => $school->id, 'student_number' => 'SUBMIT-002', 'display_name' => 'Fictional Missing Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        StudentEnrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_level_id' => $configuration['level_id'], 'academic_section_id' => $configuration['section_id'], 'academic_class_arm_id' => $configuration['class_arm_id'], 'start_date' => '2025-09-01', 'status' => 'active']);
        $base = $this->path($school, $configuration);

        $this->withToken($token)->putJson($base.'/scores', ['entries' => [['student_id' => $student->public_id, 'component_id' => $configuration['component_ids'][0], 'status' => 'entered', 'score' => 10, 'expected_version' => 0]]])->assertOk();
        $this->withToken($token)->postJson($base.'/scores/submit')->assertUnprocessable();
    }

    /** @return array<string, mixed> */
    protected function configuration(School $school, string $token, string $scoreStateRule = 'block'): array
    {
        $base = '/api/v1/schools/'.$school->public_id;
        $session = $this->withToken($token)->postJson($base.'/academic-sessions', ['name' => 'Submission Session', 'code' => 'SUB', 'start_date' => '2025-09-01', 'end_date' => '2026-07-31'])->assertCreated();
        $sessionId = $session->json('data.id');
        $term = $this->withToken($token)->postJson($base.'/academic-sessions/'.$sessionId.'/terms', ['name' => 'Submission Term', 'sequence' => 1, 'start_date' => '2025-09-01', 'end_date' => '2025-12-15'])->assertCreated();
        $termId = $term->json('data.id');
        $this->withToken($token)->postJson($base.'/academic-sessions/'.$sessionId.'/activate', ['reason' => 'Fictional submission activation'])->assertOk();
        $this->withToken($token)->postJson($base.'/academic-sessions/'.$sessionId.'/terms/'.$termId.'/activate', ['reason' => 'Fictional submission activation'])->assertOk();
        $levelId = $this->withToken($token)->postJson($base.'/academic-levels', ['name' => 'Submission Level', 'code' => 'SUBL', 'sequence' => 1])->assertCreated()->json('data.id');
        $sectionId = $this->withToken($token)->postJson($base.'/academic-levels/'.$levelId.'/sections', ['name' => 'Submission Section', 'code' => 'SUBS', 'sequence' => 1])->assertCreated()->json('data.id');
        $classArmId = $this->withToken($token)->postJson($base.'/academic-levels/'.$levelId.'/sections/'.$sectionId.'/class-arms', ['name' => 'Submission Arm', 'code' => 'SUBA', 'capacity' => 30])->assertCreated()->json('data.id');
        $subjectId = $this->withToken($token)->postJson($base.'/subjects', ['name' => 'Submission Subject', 'code' => 'SUBJ'])->assertCreated()->json('data.id');
        $offerings = $base.'/academic-sessions/'.$sessionId.'/terms/'.$termId.'/subject-offerings';
        $offeringId = $this->withToken($token)->postJson($offerings, ['class_arm_id' => $classArmId, 'subject_id' => $subjectId])->assertCreated()->json('data.id');
        $this->withToken($token)->putJson($offerings.'/'.$offeringId.'/assessment-scheme', ['name' => 'Submission Scheme', 'total_marks' => 100, 'components' => [['name' => 'CA', 'category' => 'ca', 'max_marks' => 20, 'sequence' => 1], ['name' => 'Exam', 'category' => 'exam', 'max_marks' => 80, 'sequence' => 2]]])->assertOk();
        $policies = $offerings.'/'.$offeringId.'/assessment-policies';
        $policyId = $this->withToken($token)->postJson($policies, ['effective_start' => '2025-09-01', 'score_state_rule' => $scoreStateRule])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson($policies.'/'.$policyId.'/activate')->assertOk();
        $scaleId = $this->withToken($token)->postJson($policies.'/'.$policyId.'/grading-scales', ['name' => 'Submission Scale', 'effective_start' => '2025-09-01', 'bands' => [['grade' => 'F', 'label' => 'Fail', 'minimum_percentage' => 0, 'maximum_percentage' => 49.99, 'is_passing' => false, 'sequence' => 1], ['grade' => 'P', 'label' => 'Pass', 'minimum_percentage' => 50, 'maximum_percentage' => 100, 'is_passing' => true, 'sequence' => 2]]])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson($policies.'/'.$policyId.'/grading-scales/'.$scaleId.'/activate')->assertOk();
        $instances = $offerings.'/'.$offeringId.'/assessment-instances';
        $instanceId = $this->withToken($token)->postJson($instances, ['policy_id' => $policyId, 'grading_scale_id' => $scaleId, 'title' => 'Submission Assessment', 'assessment_date' => '2025-10-10'])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson($instances.'/'.$instanceId.'/open')->assertOk();
        $access = $base.'/academic-sessions/'.$sessionId.'/terms/'.$termId.'/subject-offerings/'.$offeringId.'/assessment-instances/'.$instanceId.'/access';
        $this->withToken($token)->putJson($access, ['opens_at' => '2025-10-01T00:00:00+00:00', 'closes_at' => '2025-11-30T23:59:59+00:00'])->assertOk();
        $this->withToken($token)->postJson($access.'/activate')->assertOk();
        $this->travelTo('2025-10-10 10:00:00+00:00');
        $componentIds = AcademicAssessmentPolicyComponent::query()->whereHas('version', fn ($query) => $query->where('public_id', $policyId))->orderBy('sequence')->pluck('public_id')->all();

        return ['session' => $sessionId, 'session_id' => AcademicSession::query()->where('public_id', $sessionId)->value('id'), 'term' => $termId, 'term_id' => AcademicTerm::query()->where('public_id', $termId)->value('id'), 'level_id' => AcademicLevel::query()->where('public_id', $levelId)->value('id'), 'section_id' => AcademicSection::query()->where('public_id', $sectionId)->value('id'), 'class_arm_id' => AcademicClassArm::query()->where('public_id', $classArmId)->value('id'), 'offering' => $offeringId, 'instance' => $instanceId, 'component_ids' => $componentIds];
    }

    /** @param array<string, mixed> $configuration */
    protected function path(School $school, array $configuration): string
    {
        return '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/subject-offerings/'.$configuration['offering'].'/assessment-instances/'.$configuration['instance'];
    }

    /** @return array{0: School, 1: UserIdentity} */
    protected function schoolWithRole(string $roleKey, string $email, string $password): array
    {
        $school = School::factory()->create(['name' => 'Fictional Submission School']);
        $identity = UserIdentity::factory()->withPassword($password)->create();
        $identity->contacts()->update(['canonical_value' => $email, 'verified_at' => now()]);
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $identity->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', $roleKey)->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);

        return [$school, $identity];
    }

    protected function loginAndSelect(UserIdentity $identity, School $school, string $password): string
    {
        $token = $this->postJson('/api/v1/auth/login', ['login' => $identity->contacts()->firstOrFail()->canonical_value, 'password' => $password])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return $token;
    }
}
