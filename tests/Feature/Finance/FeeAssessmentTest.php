<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Contexts\Finance\Domain\Models\FeeAssessmentRun;
use App\Contexts\Finance\Domain\Models\FeeInvoice;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;
use Tests\Feature\Academic\AcademicScoreSubmissionTest;

final class FeeAssessmentTest extends AcademicScoreSubmissionTest
{
    public function test_active_plan_generates_idempotent_draft_invoices_and_can_be_finalized(): void
    {
        [$school, $actor] = $this->schoolWithRole('school_admin', 'fee-assessment-admin@example.com', 'fee-assessment-password');
        $token = $this->loginAndSelect($actor, $school, 'fee-assessment-password');
        $configuration = $this->configuration($school, $token);
        $student = Student::create(['school_id' => $school->id, 'student_number' => 'FEE-001', 'display_name' => 'Fictional Fee Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        StudentEnrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_level_id' => $configuration['level_id'], 'academic_section_id' => $configuration['section_id'], 'academic_class_arm_id' => $configuration['class_arm_id'], 'start_date' => '2025-09-01', 'status' => 'active']);
        $category = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/fee-categories', ['code' => 'TUITION', 'name' => 'Tuition'])->assertCreated()->json('data.category.id');
        $plan = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/fee-plans', ['session_id' => $configuration['session'], 'term_id' => $configuration['term'], 'name' => 'Fictional Term Plan', 'currency' => 'NGN', 'effective_from' => '2025-09-01', 'effective_to' => '2025-12-15', 'lines' => [['category_id' => $category, 'amount_minor_units' => 18000000, 'sequence' => 1, 'is_required' => true]]])->assertCreated()->json('data.plan.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/fee-plans/'.$plan.'/activate')->assertOk();
        $base = '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/fee-assessments';

        $created = $this->withToken($token)->postJson($base, ['plan_id' => $plan, 'due_date' => '2025-12-01'])->assertCreated()->assertJsonPath('data.assessment.status', 'draft')->assertJsonPath('data.assessment.item_count', 1)->assertJsonPath('data.assessment.invoice_count', 1);
        $assessment = $created->json('data.assessment.id');
        $this->withToken($token)->postJson($base, ['plan_id' => $plan, 'due_date' => '2025-12-01'])->assertCreated()->assertJsonPath('data.assessment.id', $assessment);
        self::assertSame(1, FeeAssessmentRun::query()->count());
        self::assertSame(1, FeeInvoice::query()->count());
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/fee-assessments/'.$assessment.'/finalize')->assertOk()->assertJsonPath('data.assessment.status', 'finalized');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/fee-assessments/'.$assessment.'/finalize')->assertOk()->assertJsonPath('data.assessment.status', 'finalized');
        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/invoices')->assertOk()->assertJsonPath('data.invoices.0.status', 'issued')->assertJsonPath('data.invoices.0.total_minor_units', 18000000);
    }

    public function test_draft_or_cross_school_plan_is_not_an_assessment_source(): void
    {
        [$school, $actor] = $this->schoolWithRole('school_admin', 'fee-assessment-admin@example.com', 'fee-assessment-admin-password');
        $token = $this->loginAndSelect($actor, $school, 'fee-assessment-admin-password');
        $configuration = $this->configuration($school, $token);
        $category = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/fee-categories', ['code' => 'BOOKS', 'name' => 'Books'])->assertCreated()->json('data.category.id');
        $plan = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/fee-plans', ['session_id' => $configuration['session'], 'term_id' => $configuration['term'], 'name' => 'Draft Plan', 'currency' => 'NGN', 'effective_from' => '2025-09-01', 'effective_to' => '2025-12-15', 'lines' => [['category_id' => $category, 'amount_minor_units' => 100, 'sequence' => 1, 'is_required' => false]]])->assertCreated()->json('data.plan.id');

        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/fee-assessments', ['plan_id' => $plan])->assertNotFound();
        $this->withToken($token)->postJson('/api/v1/schools/00000000-0000-7000-8000-000000000000/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/fee-assessments', ['plan_id' => $plan])->assertNotFound();
        self::assertSame(0, FeeAssessmentRun::query()->count());
    }
}
