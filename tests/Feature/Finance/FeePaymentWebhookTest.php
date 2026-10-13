<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Contexts\Finance\Domain\Models\FeeInvoice;
use App\Contexts\Finance\Domain\Models\FeePaymentIntent;
use App\Contexts\Finance\Domain\Models\FeePaymentWebhookEvent;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Academic\AcademicScoreSubmissionTest;

final class FeePaymentWebhookTest extends AcademicScoreSubmissionTest
{
    public function test_signed_webhook_completes_an_intent_and_replays_idempotently(): void
    {
        config(['services.payment_webhooks.providers.deterministic.secret' => 'test-webhook-secret']);
        [$school, $actor] = $this->schoolWithRole('school_admin', 'webhook-admin@example.com', 'webhook-password');
        $token = $this->loginAndSelect($actor, $school, 'webhook-password');
        $invoice = $this->issuedInvoice($school, $token);
        $path = '/api/v1/schools/'.$school->public_id.'/invoices/'.$invoice->public_id.'/payment-intents';
        $created = $this->withToken($token)->withHeader('Idempotency-Key', 'webhook-intent-0001')->postJson($path, ['currency' => 'NGN', 'amount_minor_units' => 4000])->assertCreated();
        $intentId = $created->json('data.payment_intent.id');
        $intent = FeePaymentIntent::query()->firstOrFail();
        $intent->update(['status' => 'pending']);

        $payload = [
            'event_id' => 'evt-webhook-001',
            'event_type' => 'payment.completed',
            'payment_intent_id' => $intentId,
            'invoice_id' => (string) $invoice->public_id,
            'school_id' => (string) $school->public_id,
            'currency' => 'NGN',
            'amount_minor_units' => 4000,
            'occurred_at' => now()->toAtomString(),
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->webhook($body)->assertOk()->assertJsonPath('data.webhook.status', 'accepted')->assertJsonPath('data.webhook.intent_status', 'completed');
        self::assertSame('completed', (string) $intent->fresh()->status);
        self::assertCount(1, FeePaymentWebhookEvent::query()->get());

        $this->webhook($body)->assertOk()->assertJsonPath('data.webhook.status', 'duplicate');
        self::assertCount(1, FeePaymentWebhookEvent::query()->get());
    }

    public function test_invalid_signature_stale_event_and_conflicting_terminal_event_do_not_mutate(): void
    {
        config(['services.payment_webhooks.providers.deterministic.secret' => 'test-webhook-secret']);
        [$school, $actor] = $this->schoolWithRole('school_admin', 'webhook-safety@example.com', 'webhook-safety-password');
        $token = $this->loginAndSelect($actor, $school, 'webhook-safety-password');
        $invoice = $this->issuedInvoice($school, $token);
        $path = '/api/v1/schools/'.$school->public_id.'/invoices/'.$invoice->public_id.'/payment-intents';
        $created = $this->withToken($token)->withHeader('Idempotency-Key', 'webhook-intent-0002')->postJson($path, ['currency' => 'NGN', 'amount_minor_units' => 2500])->assertCreated();
        $intentId = $created->json('data.payment_intent.id');
        $intent = FeePaymentIntent::query()->firstOrFail();
        $intent->update(['status' => 'pending']);

        $payload = ['event_id' => 'evt-webhook-002', 'event_type' => 'payment.completed', 'payment_intent_id' => $intentId, 'invoice_id' => (string) $invoice->public_id, 'school_id' => (string) $school->public_id, 'currency' => 'NGN', 'amount_minor_units' => 2500, 'occurred_at' => now()->toAtomString()];
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->call('POST', '/api/v1/payment-webhooks/deterministic', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYMENT_SIGNATURE' => 'sha256=invalid'], $body)->assertUnauthorized();
        $stale = $payload;
        $stale['event_id'] = 'evt-webhook-stale';
        $stale['occurred_at'] = now()->subMinutes(20)->toAtomString();
        $staleBody = json_encode($stale, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->webhook($staleBody)->assertUnprocessable();
        self::assertSame('pending', (string) $intent->fresh()->status);

        $this->webhook($body)->assertOk();
        $conflict = $payload;
        $conflict['event_id'] = 'evt-webhook-003';
        $conflict['event_type'] = 'payment.cancelled';
        $conflictBody = json_encode($conflict, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->webhook($conflictBody)->assertStatus(409);
        self::assertSame('completed', (string) $intent->fresh()->status);
    }

    /** @return TestResponse<JsonResponse> */
    private function webhook(string $body): TestResponse
    {
        return $this->call('POST', '/api/v1/payment-webhooks/deterministic', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYMENT_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, 'test-webhook-secret')], $body);
    }

    private function issuedInvoice(School $school, string $token): FeeInvoice
    {
        $configuration = $this->configuration($school, $token);
        $student = Student::create(['school_id' => $school->id, 'student_number' => 'WEBHOOK-'.fake()->unique()->numberBetween(100, 999), 'display_name' => 'Fictional Webhook Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        StudentEnrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_level_id' => $configuration['level_id'], 'academic_section_id' => $configuration['section_id'], 'academic_class_arm_id' => $configuration['class_arm_id'], 'start_date' => '2025-09-01', 'status' => 'active']);
        $base = '/api/v1/schools/'.$school->public_id;
        $category = $this->withToken($token)->postJson($base.'/fee-categories', ['code' => 'WEBHOOK-FEE', 'name' => 'Webhook Fee'])->assertCreated()->json('data.category.id');
        $plan = $this->withToken($token)->postJson($base.'/fee-plans', ['session_id' => $configuration['session'], 'term_id' => $configuration['term'], 'name' => 'Webhook Plan', 'currency' => 'NGN', 'effective_from' => '2025-09-01', 'effective_to' => '2025-12-15', 'lines' => [['category_id' => $category, 'amount_minor_units' => 10000, 'sequence' => 1, 'is_required' => true]]])->assertCreated()->json('data.plan.id');
        $this->withToken($token)->postJson($base.'/fee-plans/'.$plan.'/activate')->assertOk();
        $assessment = $this->withToken($token)->postJson($base.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/fee-assessments', ['plan_id' => $plan, 'due_date' => '2025-12-01'])->assertCreated()->json('data.assessment.id');
        $this->withToken($token)->postJson($base.'/fee-assessments/'.$assessment.'/finalize')->assertOk();

        return FeeInvoice::query()->firstOrFail();
    }
}
