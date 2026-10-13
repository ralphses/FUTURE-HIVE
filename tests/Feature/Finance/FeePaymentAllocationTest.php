<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Contexts\Finance\Domain\Models\FeeInvoice;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;
use Tests\Feature\Academic\AcademicScoreSubmissionTest;

final class FeePaymentAllocationTest extends AcademicScoreSubmissionTest
{
    public function test_issued_invoice_allocations_are_idempotent_and_balance_limited(): void
    {
        [$school, $actor] = $this->schoolWithRole('school_admin', 'allocation-admin@example.com', 'allocation-password');
        $token = $this->loginAndSelect($actor, $school, 'allocation-password');
        $configuration = $this->configuration($school, $token);
        $student = Student::create(['school_id' => $school->id, 'student_number' => 'PAY-001', 'display_name' => 'Fictional Payment Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        StudentEnrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_level_id' => $configuration['level_id'], 'academic_section_id' => $configuration['section_id'], 'academic_class_arm_id' => $configuration['class_arm_id'], 'start_date' => '2025-09-01', 'status' => 'active']);
        $base = '/api/v1/schools/'.$school->public_id;
        $category = $this->withToken($token)->postJson($base.'/fee-categories', ['code' => 'PAY-TUITION', 'name' => 'Payment Tuition'])->assertCreated()->json('data.category.id');
        $plan = $this->withToken($token)->postJson($base.'/fee-plans', ['session_id' => $configuration['session'], 'term_id' => $configuration['term'], 'name' => 'Payment Plan', 'currency' => 'NGN', 'effective_from' => '2025-09-01', 'effective_to' => '2025-12-15', 'lines' => [['category_id' => $category, 'amount_minor_units' => 10000, 'sequence' => 1, 'is_required' => true]]])->assertCreated()->json('data.plan.id');
        $this->withToken($token)->postJson($base.'/fee-plans/'.$plan.'/activate')->assertOk();
        $assessment = $this->withToken($token)->postJson($base.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/fee-assessments', ['plan_id' => $plan, 'due_date' => '2025-12-01'])->assertCreated()->json('data.assessment.id');
        $this->withToken($token)->postJson($base.'/fee-assessments/'.$assessment.'/finalize')->assertOk();
        $invoice = FeeInvoice::query()->firstOrFail();
        $path = $base.'/invoices/'.$invoice->public_id.'/payment-allocations';

        $payload = ['payment_reference' => 'PAYMENT-001', 'currency' => 'NGN', 'payment_amount_minor_units' => 6000, 'amount_minor_units' => 4000];
        $allocation = $this->withToken($token)->postJson($path, $payload)->assertCreated()->assertJsonPath('data.allocation.amount_minor_units', 4000)->json('data.allocation.id');
        $this->withToken($token)->postJson($path, $payload)->assertCreated()->assertJsonPath('data.allocation.id', $allocation);
        $this->withToken($token)->postJson($path, ['payment_reference' => 'PAYMENT-002', 'currency' => 'NGN', 'payment_amount_minor_units' => 6000, 'amount_minor_units' => 7000])->assertUnprocessable();
        $this->withToken($token)->getJson($path)->assertOk()->assertJsonCount(1, 'data.allocations');
    }

    public function test_draft_and_currency_mismatch_allocations_are_rejected(): void
    {
        [$school, $actor] = $this->schoolWithRole('school_admin', 'allocation-draft@example.com', 'allocation-draft-password');
        $token = $this->loginAndSelect($actor, $school, 'allocation-draft-password');
        $configuration = $this->configuration($school, $token);
        $student = Student::create(['school_id' => $school->id, 'student_number' => 'PAY-002', 'display_name' => 'Fictional Draft Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        StudentEnrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_level_id' => $configuration['level_id'], 'academic_section_id' => $configuration['section_id'], 'academic_class_arm_id' => $configuration['class_arm_id'], 'start_date' => '2025-09-01', 'status' => 'active']);
        $base = '/api/v1/schools/'.$school->public_id;
        $category = $this->withToken($token)->postJson($base.'/fee-categories', ['code' => 'PAY-DRAFT', 'name' => 'Draft Payment'])->assertCreated()->json('data.category.id');
        $plan = $this->withToken($token)->postJson($base.'/fee-plans', ['session_id' => $configuration['session'], 'term_id' => $configuration['term'], 'name' => 'Draft Payment Plan', 'currency' => 'NGN', 'effective_from' => '2025-09-01', 'effective_to' => '2025-12-15', 'lines' => [['category_id' => $category, 'amount_minor_units' => 8000, 'sequence' => 1, 'is_required' => true]]])->assertCreated()->json('data.plan.id');
        $this->withToken($token)->postJson($base.'/fee-plans/'.$plan.'/activate')->assertOk();
        $assessment = $this->withToken($token)->postJson($base.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/fee-assessments', ['plan_id' => $plan, 'due_date' => '2025-12-01'])->assertCreated()->json('data.assessment.id');
        $invoice = FeeInvoice::query()->firstOrFail();
        $path = $base.'/invoices/'.$invoice->public_id.'/payment-allocations';

        $this->withToken($token)->postJson($path, ['payment_reference' => 'PAYMENT-DRAFT', 'currency' => 'NGN', 'payment_amount_minor_units' => 1000, 'amount_minor_units' => 1000])->assertNotFound();
    }
}
