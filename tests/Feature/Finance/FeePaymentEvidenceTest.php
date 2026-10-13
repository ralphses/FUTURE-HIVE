<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Contexts\Finance\Domain\Models\FeeInvoice;
use App\Contexts\Finance\Domain\Models\FeePaymentAllocation;
use App\Contexts\Finance\Domain\Models\FeePaymentEvidence;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;
use Tests\Feature\Academic\AcademicScoreSubmissionTest;

final class FeePaymentEvidenceTest extends AcademicScoreSubmissionTest
{
    public function test_finance_user_can_record_and_verify_manual_evidence_without_settlement_mutation(): void
    {
        [$school, $actor] = $this->schoolWithRole('school_admin', 'evidence-admin@example.com', 'evidence-password');
        $token = $this->loginAndSelect($actor, $school, 'evidence-password');
        $invoice = $this->issuedInvoice($school, $token);
        $path = '/api/v1/schools/'.$school->public_id.'/invoices/'.$invoice->public_id.'/payment-evidence';
        $payload = ['amount_minor_units' => 4000, 'currency' => 'NGN', 'payment_date' => '2025-10-04', 'reference' => 'BANK-REF-001', 'note' => 'Cash office deposit'];

        $response = $this->withToken($token)->withHeader('Idempotency-Key', 'evidence-key-0001')->postJson($path, $payload)
            ->assertCreated()
            ->assertJsonPath('data.payment_evidence.amount_minor_units', 4000)
            ->assertJsonPath('data.payment_evidence.status', 'pending');
        self::assertStringNotContainsString('BANK-REF-001', (string) $response->getContent());
        $evidenceId = $response->json('data.payment_evidence.id');

        $this->withToken($token)->withHeader('Idempotency-Key', 'evidence-key-0001')->postJson($path, $payload)
            ->assertCreated()->assertJsonPath('data.payment_evidence.id', $evidenceId);
        self::assertSame(1, FeePaymentEvidence::query()->count());
        self::assertSame(0, FeePaymentAllocation::query()->count());

        $this->withToken($token)->postJson($path.'/'.$evidenceId.'/verify')->assertOk()->assertJsonPath('data.payment_evidence.status', 'verified');
        $this->withToken($token)->postJson($path.'/'.$evidenceId.'/verify')->assertOk()->assertJsonPath('data.payment_evidence.status', 'verified');
        self::assertSame('verified', (string) FeePaymentEvidence::query()->firstOrFail()->status);
    }

    public function test_evidence_rejects_draft_invoice_currency_and_overallocation(): void
    {
        [$school, $actor] = $this->schoolWithRole('school_admin', 'evidence-validation@example.com', 'evidence-validation-password');
        $token = $this->loginAndSelect($actor, $school, 'evidence-validation-password');
        $configuration = $this->configuration($school, $token);
        $student = Student::create(['school_id' => $school->id, 'student_number' => 'EVIDENCE-DRAFT', 'display_name' => 'Fictional Evidence Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        StudentEnrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_level_id' => $configuration['level_id'], 'academic_section_id' => $configuration['section_id'], 'academic_class_arm_id' => $configuration['class_arm_id'], 'start_date' => '2025-09-01', 'status' => 'active']);
        $base = '/api/v1/schools/'.$school->public_id;
        $category = $this->withToken($token)->postJson($base.'/fee-categories', ['code' => 'EVIDENCE-FEE', 'name' => 'Evidence Fee'])->assertCreated()->json('data.category.id');
        $plan = $this->withToken($token)->postJson($base.'/fee-plans', ['session_id' => $configuration['session'], 'term_id' => $configuration['term'], 'name' => 'Evidence Plan', 'currency' => 'NGN', 'effective_from' => '2025-09-01', 'effective_to' => '2025-12-15', 'lines' => [['category_id' => $category, 'amount_minor_units' => 1000, 'sequence' => 1, 'is_required' => true]]])->assertCreated()->json('data.plan.id');
        $this->withToken($token)->postJson($base.'/fee-plans/'.$plan.'/activate')->assertOk();
        $assessment = $this->withToken($token)->postJson($base.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/fee-assessments', ['plan_id' => $plan, 'due_date' => '2025-12-01'])->assertCreated()->json('data.assessment.id');
        $invoice = FeeInvoice::query()->firstOrFail();
        $path = $base.'/invoices/'.$invoice->public_id.'/payment-evidence';

        $this->withToken($token)->withHeader('Idempotency-Key', 'evidence-draft-0001')->postJson($path, ['amount_minor_units' => 100, 'currency' => 'NGN', 'payment_date' => '2025-10-04', 'reference' => 'DRAFT-001'])->assertNotFound();
        $this->withToken($token)->withHeader('Idempotency-Key', 'evidence-draft-0002')->postJson($path, ['amount_minor_units' => 100, 'currency' => 'USD', 'payment_date' => '2025-10-04', 'reference' => 'DRAFT-002'])->assertNotFound();
        self::assertSame(0, FeePaymentEvidence::query()->count());
    }

    public function test_rejection_requires_a_safe_reason_and_verified_evidence_is_immutable(): void
    {
        [$school, $actor] = $this->schoolWithRole('school_admin', 'evidence-review@example.com', 'evidence-review-password');
        $token = $this->loginAndSelect($actor, $school, 'evidence-review-password');
        $invoice = $this->issuedInvoice($school, $token);
        $path = '/api/v1/schools/'.$school->public_id.'/invoices/'.$invoice->public_id.'/payment-evidence';
        $evidence = $this->withToken($token)->withHeader('Idempotency-Key', 'evidence-review-0001')->postJson($path, ['amount_minor_units' => 2000, 'currency' => 'NGN', 'payment_date' => '2025-10-04', 'reference' => 'REVIEW-001'])->assertCreated()->json('data.payment_evidence.id');
        $this->withToken($token)->postJson($path.'/'.$evidence.'/reject', ['reason' => 'invalid / reason'])->assertUnprocessable();
        $this->withToken($token)->postJson($path.'/'.$evidence.'/reject', ['reason' => 'Duplicate deposit'])->assertOk()->assertJsonPath('data.payment_evidence.status', 'rejected');
        $this->withToken($token)->postJson($path.'/'.$evidence.'/verify')->assertNotFound();
    }

    private function issuedInvoice(School $school, string $token): FeeInvoice
    {
        $configuration = $this->configuration($school, $token);
        $student = Student::create(['school_id' => $school->id, 'student_number' => 'EVIDENCE-'.fake()->unique()->numberBetween(100, 999), 'display_name' => 'Fictional Evidence Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        StudentEnrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_level_id' => $configuration['level_id'], 'academic_section_id' => $configuration['section_id'], 'academic_class_arm_id' => $configuration['class_arm_id'], 'start_date' => '2025-09-01', 'status' => 'active']);
        $base = '/api/v1/schools/'.$school->public_id;
        $category = $this->withToken($token)->postJson($base.'/fee-categories', ['code' => 'EVIDENCE-TUITION', 'name' => 'Evidence Tuition'])->assertCreated()->json('data.category.id');
        $plan = $this->withToken($token)->postJson($base.'/fee-plans', ['session_id' => $configuration['session'], 'term_id' => $configuration['term'], 'name' => 'Evidence Plan', 'currency' => 'NGN', 'effective_from' => '2025-09-01', 'effective_to' => '2025-12-15', 'lines' => [['category_id' => $category, 'amount_minor_units' => 10000, 'sequence' => 1, 'is_required' => true]]])->assertCreated()->json('data.plan.id');
        $this->withToken($token)->postJson($base.'/fee-plans/'.$plan.'/activate')->assertOk();
        $assessment = $this->withToken($token)->postJson($base.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/fee-assessments', ['plan_id' => $plan, 'due_date' => '2025-12-01'])->assertCreated()->json('data.assessment.id');
        $this->withToken($token)->postJson($base.'/fee-assessments/'.$assessment.'/finalize')->assertOk();

        return FeeInvoice::query()->firstOrFail();
    }
}
