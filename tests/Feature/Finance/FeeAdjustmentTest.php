<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Contexts\Finance\Domain\Models\FeeInvoice;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;
use Tests\Feature\Academic\AcademicScoreSubmissionTest;

final class FeeAdjustmentTest extends AcademicScoreSubmissionTest
{
    public function test_fixed_adjustment_is_approved_and_applied_once_to_a_draft_invoice(): void
    {
        [$school, $actor] = $this->schoolWithRole('school_admin', 'adjustment-admin@example.com', 'adjustment-password');
        $token = $this->loginAndSelect($actor, $school, 'adjustment-password');
        $configuration = $this->configuration($school, $token);
        $student = Student::create(['school_id' => $school->id, 'student_number' => 'ADJ-001', 'display_name' => 'Fictional Adjustment Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        StudentEnrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_level_id' => $configuration['level_id'], 'academic_section_id' => $configuration['section_id'], 'academic_class_arm_id' => $configuration['class_arm_id'], 'start_date' => '2025-09-01', 'status' => 'active']);
        $category = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/fee-categories', ['code' => 'ADJ-TUITION', 'name' => 'Adjustment Tuition'])->assertCreated()->json('data.category.id');
        $plan = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/fee-plans', ['session_id' => $configuration['session'], 'term_id' => $configuration['term'], 'name' => 'Adjustment Plan', 'currency' => 'NGN', 'effective_from' => '2025-09-01', 'effective_to' => '2025-12-15', 'lines' => [['category_id' => $category, 'amount_minor_units' => 10000, 'sequence' => 1, 'is_required' => true]]])->assertCreated()->json('data.plan.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/fee-plans/'.$plan.'/activate')->assertOk();
        $assessmentPath = '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/fee-assessments';
        $assessment = $this->withToken($token)->postJson($assessmentPath, ['plan_id' => $plan, 'due_date' => '2025-12-01'])->assertCreated()->json('data.assessment.id');
        $invoice = FeeInvoice::query()->firstOrFail();
        $program = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/fee-adjustment-programs', ['code' => 'SCHOLAR-ONE', 'name' => 'Fictional Scholarship', 'adjustment_type' => 'scholarship', 'calculation_method' => 'fixed', 'fixed_amount_minor_units' => 2500])->assertCreated()->json('data.program.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/fee-adjustment-programs/'.$program.'/activate')->assertOk();
        $adjustmentPath = '/api/v1/schools/'.$school->public_id.'/invoices/'.$invoice->public_id.'/adjustments';
        $adjustment = $this->withToken($token)->postJson($adjustmentPath, ['program_id' => $program, 'reason' => 'Approved fictional scholarship'])->assertCreated()->assertJsonPath('data.adjustment.amount_minor_units', 2500)->json('data.adjustment.id');
        $this->withToken($token)->postJson($adjustmentPath.'/'.$adjustment.'/approve')->assertOk()->assertJsonPath('data.adjustment.status', 'approved');
        $this->withToken($token)->postJson($adjustmentPath.'/'.$adjustment.'/apply')->assertOk()->assertJsonPath('data.adjustment.status', 'applied');
        $this->withToken($token)->postJson($adjustmentPath.'/'.$adjustment.'/apply')->assertOk()->assertJsonPath('data.adjustment.status', 'applied');
        self::assertSame(7500, (int) $invoice->fresh()->total_minor_units);
        self::assertSame($assessment, $invoice->assessment()->value('public_id'));
    }

    public function test_percentage_adjustment_uses_bounded_integer_rounding_and_issued_invoices_are_immutable(): void
    {
        [$school, $actor] = $this->schoolWithRole('school_admin', 'percentage-admin@example.com', 'percentage-password');
        $token = $this->loginAndSelect($actor, $school, 'percentage-password');
        $configuration = $this->configuration($school, $token);
        $student = Student::create(['school_id' => $school->id, 'student_number' => 'ADJ-002', 'display_name' => 'Fictional Percentage Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        StudentEnrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_level_id' => $configuration['level_id'], 'academic_section_id' => $configuration['section_id'], 'academic_class_arm_id' => $configuration['class_arm_id'], 'start_date' => '2025-09-01', 'status' => 'active']);
        $category = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/fee-categories', ['code' => 'ADJ-BOOKS', 'name' => 'Adjustment Books'])->assertCreated()->json('data.category.id');
        $plan = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/fee-plans', ['session_id' => $configuration['session'], 'term_id' => $configuration['term'], 'name' => 'Percentage Plan', 'currency' => 'NGN', 'effective_from' => '2025-09-01', 'effective_to' => '2025-12-15', 'lines' => [['category_id' => $category, 'amount_minor_units' => 10001, 'sequence' => 1, 'is_required' => true]]])->assertCreated()->json('data.plan.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/fee-plans/'.$plan.'/activate')->assertOk();
        $assessmentPath = '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/fee-assessments';
        $assessment = $this->withToken($token)->postJson($assessmentPath, ['plan_id' => $plan, 'due_date' => '2025-12-01'])->assertCreated()->json('data.assessment.id');
        $invoice = FeeInvoice::query()->firstOrFail();
        $program = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/fee-adjustment-programs', ['code' => 'PERCENT-ONE', 'name' => 'Fictional Percentage Waiver', 'adjustment_type' => 'waiver', 'calculation_method' => 'percentage', 'percentage' => 25])->assertCreated()->json('data.program.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/fee-adjustment-programs/'.$program.'/activate')->assertOk();
        $adjustmentPath = '/api/v1/schools/'.$school->public_id.'/invoices/'.$invoice->public_id.'/adjustments';
        $this->withToken($token)->postJson($adjustmentPath, ['program_id' => $program, 'reason' => 'Fictional percentage waiver'])->assertCreated()->assertJsonPath('data.adjustment.amount_minor_units', 2500);
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/fee-assessments/'.$assessment.'/finalize')->assertOk();
        $this->withToken($token)->postJson($adjustmentPath, ['program_id' => $program, 'reason' => 'Should not change issued invoice'])->assertNotFound();
        self::assertSame(10001, (int) $invoice->fresh()->total_minor_units);
    }
}
