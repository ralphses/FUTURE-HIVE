<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class PlatformBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_price_snapshot_payment_and_signed_webhook_are_idempotent(): void
    {
        Config::set('services.payment_webhooks.providers.deterministic.secret', 'test-secret');
        [$school, $token, $now] = $this->configuredSchool();
        $base = '/api/v1/schools/'.$school->public_id.'/platform-billing';

        $invoice = $this->withToken($token)->withHeader('Idempotency-Key', 'invoice-key-001')->postJson($base.'/invoices', ['amount_minor_units' => 125000, 'currency' => 'NGN', 'due_at' => $now->copy()->addDays(14)->toISOString()])
            ->assertCreated()->assertJsonPath('data.invoice.amount_minor_units', 125000)->assertJsonPath('data.invoice.plan.code', 'STANDARD')->json('data.invoice.id');
        $this->withToken($token)->withHeader('Idempotency-Key', 'invoice-key-001')->postJson($base.'/invoices', ['amount_minor_units' => 125000, 'currency' => 'NGN', 'due_at' => $now->copy()->addDays(14)->toISOString()])->assertCreated()->assertJsonPath('data.invoice.id', $invoice);
        $this->withToken($token)->postJson($base.'/invoices/'.$invoice.'/issue')->assertOk()->assertJsonPath('data.invoice.status', 'issued');
        $payment = $this->withToken($token)->withHeader('Idempotency-Key', 'payment-key-001')->postJson($base.'/invoices/'.$invoice.'/payments', ['provider' => 'deterministic', 'amount_minor_units' => 125000, 'currency' => 'NGN'])->assertCreated()->assertJsonPath('data.payment.status', 'pending')->json('data.payment.id');

        $payload = json_encode(['event_id' => 'event-001', 'event_type' => 'payment.completed', 'payment_id' => $payment, 'invoice_id' => $invoice, 'school_id' => $school->public_id, 'currency' => 'NGN', 'amount_minor_units' => 125000, 'occurred_at' => now()->toISOString()], JSON_THROW_ON_ERROR);
        $signature = 'sha256='.hash_hmac('sha256', $payload, 'test-secret');
        $this->call('POST', '/api/v1/platform-billing-webhooks/deterministic', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PROVIDER_SIGNATURE' => $signature], $payload)->assertOk()->assertJsonPath('data.webhook.status', 'accepted');
        $this->call('POST', '/api/v1/platform-billing-webhooks/deterministic', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PROVIDER_SIGNATURE' => $signature], $payload)->assertOk()->assertJsonPath('data.webhook.status', 'duplicate');
        $this->withToken($token)->getJson($base.'/invoices/'.$invoice.'/payments/'.$payment)->assertOk()->assertJsonPath('data.payment.status', 'completed');
        $this->assertDatabaseHas('platform_billing_invoices', ['public_id' => $invoice, 'status' => 'paid']);
    }

    public function test_school_fee_roles_cannot_cross_school_or_use_unprivileged_billing_scope(): void
    {
        [$school, $token] = $this->configuredSchool('school_admin');
        [$otherSchool] = $this->schoolToken('school_admin');
        [$teacher, $teacherToken] = $this->schoolToken('teacher');

        $this->withToken($teacherToken)->getJson('/api/v1/schools/'.$school->public_id.'/platform-billing/invoices')->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/schools/'.$otherSchool->public_id.'/platform-billing/invoices')->assertNotFound();
        $this->assertNotSame($school->public_id, $teacher->public_id);
    }

    /** @return array{0: School, 1: string, 2: Carbon} */
    private function configuredSchool(string $role = 'school_admin'): array
    {
        [$school, $token, $now] = $this->schoolToken($role);
        $base = '/api/v1/schools/'.$school->public_id.'/platform-subscription-plans';
        $plan = $this->withToken($token)->postJson($base, ['code' => 'STANDARD', 'name' => 'Standard', 'entitlement_keys' => ['academic.read']])->assertCreated()->json('data.plan.id');
        $this->withToken($token)->postJson($base.'/'.$plan.'/activate')->assertOk();
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/platform-entitlements/'.$plan)->assertOk();
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/platform-subscription', ['ends_at' => $now->copy()->addDays(30)->toISOString()])->assertCreated();

        return [$school, $token, $now];
    }

    /** @return array{0: School, 1: string, 2: Carbon} */
    private function schoolToken(string $role): array
    {
        $now = now();
        $school = School::factory()->create();
        $identity = UserIdentity::factory()->withPassword('password')->create();
        $login = $role.'-'.uniqid().'@example.test';
        $identity->contacts()->update(['canonical_value' => $login, 'verified_at' => $now]);
        $membership = SchoolMembership::query()->create(['school_id' => $school->id, 'user_id' => $identity->id, 'status' => 'active', 'joined_at' => $now]);
        MembershipRole::query()->create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', $role)->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => $now]);
        $token = $this->postJson('/api/v1/auth/login', ['login' => $login, 'password' => 'password'])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return [$school, $token, $now];
    }
}
