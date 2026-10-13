<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Contexts\Finance\Domain\Models\FeeInvoice;
use App\Contexts\Finance\Domain\Models\FinanceReceipt;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;
use Tests\Feature\Academic\AcademicScoreSubmissionTest;

final class FinanceReceiptTest extends AcademicScoreSubmissionTest
{
    public function test_verified_payment_allocation_creates_a_school_scoped_sequential_receipt_idempotently(): void
    {
        [$school, $actor] = $this->schoolWithRole('school_admin', 'receipt-admin@example.com', 'receipt-password');
        $token = $this->loginAndSelect($actor, $school, 'receipt-password');
        $invoice = $this->issuedInvoice($school, $token);
        $base = '/api/v1/schools/'.$school->public_id;
        $allocation = $this->withToken($token)->postJson($base.'/invoices/'.$invoice->public_id.'/payment-allocations', ['payment_reference' => 'RECEIPT-PAY-001', 'currency' => 'NGN', 'payment_amount_minor_units' => 4000, 'amount_minor_units' => 4000])->assertCreated()->json('data.allocation.id');
        $path = $base.'/receipts';
        $receipt = $this->withToken($token)->postJson($path, ['source_type' => 'payment_allocation', 'source_public_id' => $allocation])->assertCreated()->assertJsonPath('data.receipt.receipt_number', 'RCT-00000001')->assertJsonPath('data.receipt.amount_minor_units', 4000)->json('data.receipt.id');

        $this->withToken($token)->postJson($path, ['source_type' => 'payment_allocation', 'source_public_id' => $allocation])->assertCreated()->assertJsonPath('data.receipt.id', $receipt);
        $this->withToken($token)->getJson($path.'/'.$receipt)->assertOk()->assertJsonPath('data.receipt.status', 'issued');
        self::assertSame(1, FinanceReceipt::query()->count());
    }

    public function test_unverified_evidence_and_invalid_invoice_sources_cannot_create_receipts(): void
    {
        [$school, $actor] = $this->schoolWithRole('school_admin', 'receipt-validation@example.com', 'receipt-validation-password');
        $token = $this->loginAndSelect($actor, $school, 'receipt-validation-password');
        $invoice = $this->issuedInvoice($school, $token);
        $base = '/api/v1/schools/'.$school->public_id;
        $evidence = $this->withToken($token)->withHeader('Idempotency-Key', 'receipt-evidence-0001')->postJson($base.'/invoices/'.$invoice->public_id.'/payment-evidence', ['amount_minor_units' => 2000, 'currency' => 'NGN', 'payment_date' => '2025-10-04', 'reference' => 'RECEIPT-EVIDENCE-001'])->assertCreated()->json('data.payment_evidence.id');

        $this->withToken($token)->postJson($base.'/receipts', ['source_type' => 'payment_evidence', 'source_public_id' => $evidence])->assertNotFound();
        $this->withToken($token)->postJson($base.'/receipts', ['source_type' => 'invoice', 'source_public_id' => $invoice->public_id])->assertUnprocessable();
        self::assertSame(0, FinanceReceipt::query()->count());
    }

    private function issuedInvoice(School $school, string $token): FeeInvoice
    {
        $configuration = $this->configuration($school, $token);
        $student = Student::create(['school_id' => $school->id, 'student_number' => 'RECEIPT-'.fake()->unique()->numberBetween(100, 999), 'display_name' => 'Fictional Receipt Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        StudentEnrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_level_id' => $configuration['level_id'], 'academic_section_id' => $configuration['section_id'], 'academic_class_arm_id' => $configuration['class_arm_id'], 'start_date' => '2025-09-01', 'status' => 'active']);
        $base = '/api/v1/schools/'.$school->public_id;
        $category = $this->withToken($token)->postJson($base.'/fee-categories', ['code' => 'RECEIPT-TUITION', 'name' => 'Receipt Tuition'])->assertCreated()->json('data.category.id');
        $plan = $this->withToken($token)->postJson($base.'/fee-plans', ['session_id' => $configuration['session'], 'term_id' => $configuration['term'], 'name' => 'Receipt Plan', 'currency' => 'NGN', 'effective_from' => '2025-09-01', 'effective_to' => '2025-12-15', 'lines' => [['category_id' => $category, 'amount_minor_units' => 10000, 'sequence' => 1, 'is_required' => true]]])->assertCreated()->json('data.plan.id');
        $this->withToken($token)->postJson($base.'/fee-plans/'.$plan.'/activate')->assertOk();
        $assessment = $this->withToken($token)->postJson($base.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/fee-assessments', ['plan_id' => $plan, 'due_date' => '2025-12-01'])->assertCreated()->json('data.assessment.id');
        $this->withToken($token)->postJson($base.'/fee-assessments/'.$assessment.'/finalize')->assertOk();

        return FeeInvoice::query()->firstOrFail();
    }
}
