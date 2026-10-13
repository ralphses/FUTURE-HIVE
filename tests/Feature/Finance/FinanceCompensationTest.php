<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Contexts\Finance\Domain\Models\FeeInvoice;
use App\Contexts\Finance\Domain\Models\FinanceCompensationRequest;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;
use Tests\Feature\Academic\AcademicScoreSubmissionTest;

final class FinanceCompensationTest extends AcademicScoreSubmissionTest
{
    public function test_reversal_request_is_idempotent_and_self_approval_is_rejected(): void
    {
        [$school, $actor] = $this->schoolWithRole('school_admin', 'compensation-admin@example.com', 'compensation-password');
        $token = $this->loginAndSelect($actor, $school, 'compensation-password');
        $invoice = $this->issuedInvoice($school, $token);
        $base = '/api/v1/schools/'.$school->public_id;
        $allocation = $this->withToken($token)->postJson($base.'/invoices/'.$invoice->public_id.'/payment-allocations', ['payment_reference' => 'COMPENSATION-PAY-001', 'currency' => 'NGN', 'payment_amount_minor_units' => 4000, 'amount_minor_units' => 4000])->assertCreated()->json('data.allocation.id');
        $receipt = $this->withToken($token)->postJson($base.'/receipts', ['source_type' => 'payment_allocation', 'source_public_id' => $allocation])->assertCreated()->json('data.receipt.id');
        $payload = ['source_type' => 'receipt', 'source_public_id' => $receipt, 'amount_minor_units' => 4000, 'currency' => 'NGN', 'reason' => 'Approved duplicate correction'];

        $request = $this->withToken($token)->postJson($base.'/reversals', $payload)->assertCreated()->json('data.item.id');
        $this->withToken($token)->postJson($base.'/reversals', $payload)->assertCreated()->assertJsonPath('data.item.id', $request);
        $this->withToken($token)->postJson($base.'/reversals/'.$request.'/approve')->assertNotFound();
        $this->withToken($token)->postJson($base.'/reversals/'.$request.'/reject', ['reason' => 'Needs independent review'])->assertOk()->assertJsonPath('data.item.status', 'rejected');

        self::assertSame(1, FinanceCompensationRequest::query()->count());
        self::assertSame((int) $invoice->getKey(), (int) FinanceCompensationRequest::query()->firstOrFail()->fee_invoice_id);
    }

    public function test_draft_invoice_and_invalid_amounts_cannot_create_compensation_requests(): void
    {
        [$school, $actor] = $this->schoolWithRole('school_admin', 'compensation-validation@example.com', 'compensation-validation-password');
        $token = $this->loginAndSelect($actor, $school, 'compensation-validation-password');
        $invoice = $this->issuedInvoice($school, $token);
        $base = '/api/v1/schools/'.$school->public_id;

        $this->withToken($token)->postJson($base.'/reversals', ['source_type' => 'receipt', 'source_public_id' => (string) $invoice->public_id, 'amount_minor_units' => 1, 'currency' => 'NGN', 'reason' => 'Invalid source'])->assertNotFound();
        $this->withToken($token)->postJson($base.'/refunds', ['source_type' => 'receipt', 'source_public_id' => (string) $invoice->public_id, 'amount_minor_units' => 1, 'currency' => 'USD', 'reason' => 'Invalid currency'])->assertNotFound();
        self::assertSame(0, FinanceCompensationRequest::query()->count());
    }

    private function issuedInvoice(School $school, string $token): FeeInvoice
    {
        $configuration = $this->configuration($school, $token);
        $student = Student::create(['school_id' => $school->id, 'student_number' => 'COMPENSATION-'.fake()->unique()->numberBetween(100, 999), 'display_name' => 'Fictional Compensation Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        StudentEnrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_level_id' => $configuration['level_id'], 'academic_section_id' => $configuration['section_id'], 'academic_class_arm_id' => $configuration['class_arm_id'], 'start_date' => '2025-09-01', 'status' => 'active']);
        $base = '/api/v1/schools/'.$school->public_id;
        $category = $this->withToken($token)->postJson($base.'/fee-categories', ['code' => 'COMPENSATION-TUITION', 'name' => 'Compensation Tuition'])->assertCreated()->json('data.category.id');
        $plan = $this->withToken($token)->postJson($base.'/fee-plans', ['session_id' => $configuration['session'], 'term_id' => $configuration['term'], 'name' => 'Compensation Plan', 'currency' => 'NGN', 'effective_from' => '2025-09-01', 'effective_to' => '2025-12-15', 'lines' => [['category_id' => $category, 'amount_minor_units' => 10000, 'sequence' => 1, 'is_required' => true]]])->assertCreated()->json('data.plan.id');
        $this->withToken($token)->postJson($base.'/fee-plans/'.$plan.'/activate')->assertOk();
        $assessment = $this->withToken($token)->postJson($base.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/fee-assessments', ['plan_id' => $plan, 'due_date' => '2025-12-01'])->assertCreated()->json('data.assessment.id');
        $this->withToken($token)->postJson($base.'/fee-assessments/'.$assessment.'/finalize')->assertOk();

        return FeeInvoice::query()->firstOrFail();
    }
}
