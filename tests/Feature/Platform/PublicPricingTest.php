<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Contexts\Platform\Domain\Models\PlatformSubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PublicPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_readers_receive_only_active_plans_without_pricing_or_tenant_data(): void
    {
        PlatformSubscriptionPlan::query()->create(['code' => 'pro', 'name' => 'Pro', 'description' => 'For growing schools', 'version' => 1, 'entitlement_keys' => ['reports.read', 'attendance.read'], 'status' => 'active', 'activated_at' => now()]);
        PlatformSubscriptionPlan::query()->create(['code' => 'starter', 'name' => 'Starter', 'version' => 1, 'entitlement_keys' => ['dashboard.read'], 'status' => 'active', 'activated_at' => now()]);
        $draft = PlatformSubscriptionPlan::query()->create(['code' => 'hidden', 'name' => 'Hidden', 'version' => 1, 'entitlement_keys' => ['hidden'], 'status' => 'draft']);

        $response = $this->getJson('/api/v1/public/pricing');

        $response->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.code', 'pro')
            ->assertJsonPath('data.0.pricing.status', 'pricing_unavailable')
            ->assertJsonMissing(['code' => 'hidden'])
            ->assertJsonMissing(['school_id' => $draft->getAttribute('school_id')]);

        $plan = PlatformSubscriptionPlan::query()->where('code', 'pro')->firstOrFail();
        $this->getJson('/api/v1/public/pricing/'.$plan->public_id)
            ->assertOk()
            ->assertJsonPath('data.plan.availability', 'available')
            ->assertJsonMissingPath('data.plan.amount')
            ->assertJsonMissingPath('data.plan.school_id');
    }

    public function test_non_published_plans_are_unavailable_without_authentication(): void
    {
        $plan = PlatformSubscriptionPlan::query()->create(['code' => 'draft', 'name' => 'Draft', 'version' => 1, 'entitlement_keys' => [], 'status' => 'draft']);

        $this->getJson('/api/v1/public/pricing/'.$plan->public_id)->assertNotFound();
        $this->getJson('/api/v1/public/pricing/not-a-real-plan')->assertNotFound();
    }
}
