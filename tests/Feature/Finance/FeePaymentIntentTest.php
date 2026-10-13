<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Contexts\Finance\Domain\Models\FeeInvoice;
use App\Contexts\Finance\Domain\Models\FeePaymentAllocation;
use App\Contexts\Finance\Domain\Models\FeePaymentIntent;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;
use Tests\Feature\Academic\AcademicScoreSubmissionTest;

final class FeePaymentIntentTest extends AcademicScoreSubmissionTest
{
    public function test_issued_invoice_payment_intent_is_provider_agnostic_and_idempotent(): void
    {
        [$school, $actor] = $this->schoolWithRole('school_admin', 'intent-admin@example.com', 'intent-password');
        $token = $this->loginAndSelect($actor, $school, 'intent-password');
        $invoice = $this->issuedInvoice($school, $token, 'INTENT-001');
        $path = '/api/v1/schools/'.$school->public_id.'/invoices/'.$invoice->public_id.'/payment-intents';
        $payload = ['currency' => 'NGN', 'amount_minor_units' => 4000, 'payer_reference' => 'PAYER-001'];

        $first = $this->withToken($token)->withHeader('Idempotency-Key', 'intent-key-0000001')->postJson($path, $payload)
            ->assertCreated()
            ->assertJsonPath('data.payment_intent.amount_minor_units', 4000)
            ->assertJsonPath('data.payment_intent.status', 'created')
            ->assertJsonPath('data.payment_intent.next_step', 'awaiting_provider_confirmation');
        self::assertStringNotContainsString('PAYER-001', (string) $first->getContent());
        $intentId = $first->json('data.payment_intent.id');

        $this->withToken($token)->withHeader('Idempotency-Key', 'intent-key-0000001')->postJson($path, $payload)
            ->assertCreated()->assertJsonPath('data.payment_intent.id', $intentId);
        $this->withToken($token)->withHeader('Idempotency-Key', 'intent-key-0000001')->postJson($path, ['currency' => 'NGN', 'amount_minor_units' => 3000])
            ->assertStatus(409);
        self::assertSame(1, FeePaymentIntent::query()->count());
        self::assertSame(0, FeePaymentAllocation::query()->count());

        $cancelPath = $path.'/'.$intentId.'/cancel';
        $this->withToken($token)->postJson($cancelPath)->assertOk()->assertJsonPath('data.payment_intent.status', 'cancelled');
        $this->withToken($token)->postJson($cancelPath)->assertOk()->assertJsonPath('data.payment_intent.status', 'cancelled');
    }

    public function test_payment_intent_requires_issued_invoice_and_matching_currency(): void
    {
        [$school, $actor] = $this->schoolWithRole('school_admin', 'intent-draft@example.com', 'intent-draft-password');
        $token = $this->loginAndSelect($actor, $school, 'intent-draft-password');
        $configuration = $this->configuration($school, $token);
        $student = Student::create(['school_id' => $school->id, 'student_number' => 'INTENT-002', 'display_name' => 'Fictional Intent Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        StudentEnrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_level_id' => $configuration['level_id'], 'academic_section_id' => $configuration['section_id'], 'academic_class_arm_id' => $configuration['class_arm_id'], 'start_date' => '2025-09-01', 'status' => 'active']);
        $base = '/api/v1/schools/'.$school->public_id;
        $category = $this->withToken($token)->postJson($base.'/fee-categories', ['code' => 'INTENT-DRAFT', 'name' => 'Intent Draft'])->assertCreated()->json('data.category.id');
        $plan = $this->withToken($token)->postJson($base.'/fee-plans', ['session_id' => $configuration['session'], 'term_id' => $configuration['term'], 'name' => 'Intent Draft Plan', 'currency' => 'NGN', 'effective_from' => '2025-09-01', 'effective_to' => '2025-12-15', 'lines' => [['category_id' => $category, 'amount_minor_units' => 8000, 'sequence' => 1, 'is_required' => true]]])->assertCreated()->json('data.plan.id');
        $this->withToken($token)->postJson($base.'/fee-plans/'.$plan.'/activate')->assertOk();
        $assessment = $this->withToken($token)->postJson($base.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/fee-assessments', ['plan_id' => $plan, 'due_date' => '2025-12-01'])->assertCreated()->json('data.assessment.id');
        $this->withToken($token)->postJson($base.'/fee-assessments/'.$assessment.'/finalize')->assertOk();
        $invoice = FeeInvoice::query()->firstOrFail();
        $path = $base.'/invoices/'.$invoice->public_id.'/payment-intents';

        $this->withToken($token)->withHeader('Idempotency-Key', 'intent-draft-000001')->postJson($path, ['currency' => 'USD', 'amount_minor_units' => 1000])->assertUnprocessable();
    }

    private function issuedInvoice(School $school, string $token, string $studentNumber): FeeInvoice
    {
        $configuration = $this->configuration($school, $token);
        $student = Student::create(['school_id' => $school->id, 'student_number' => $studentNumber, 'display_name' => 'Fictional Intent Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        StudentEnrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_level_id' => $configuration['level_id'], 'academic_section_id' => $configuration['section_id'], 'academic_class_arm_id' => $configuration['class_arm_id'], 'start_date' => '2025-09-01', 'status' => 'active']);
        $base = '/api/v1/schools/'.$school->public_id;
        $category = $this->withToken($token)->postJson($base.'/fee-categories', ['code' => 'INTENT-TUITION', 'name' => 'Intent Tuition'])->assertCreated()->json('data.category.id');
        $plan = $this->withToken($token)->postJson($base.'/fee-plans', ['session_id' => $configuration['session'], 'term_id' => $configuration['term'], 'name' => 'Intent Plan', 'currency' => 'NGN', 'effective_from' => '2025-09-01', 'effective_to' => '2025-12-15', 'lines' => [['category_id' => $category, 'amount_minor_units' => 10000, 'sequence' => 1, 'is_required' => true]]])->assertCreated()->json('data.plan.id');
        $this->withToken($token)->postJson($base.'/fee-plans/'.$plan.'/activate')->assertOk();
        $assessment = $this->withToken($token)->postJson($base.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/fee-assessments', ['plan_id' => $plan, 'due_date' => '2025-12-01'])->assertCreated()->json('data.assessment.id');
        $this->withToken($token)->postJson($base.'/fee-assessments/'.$assessment.'/finalize')->assertOk();

        return FeeInvoice::query()->firstOrFail();
    }
}
