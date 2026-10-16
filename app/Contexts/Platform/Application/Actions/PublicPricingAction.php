<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

use App\Contexts\Platform\Domain\Models\PlatformSubscriptionPlan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;

final class PublicPricingAction
{
    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function index(int $page = 1, int $perPage = 25): LengthAwarePaginator
    {
        return PlatformSubscriptionPlan::query()
            ->where('status', 'active')
            ->orderBy('code')
            ->orderByDesc('version')
            ->orderBy('public_id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->through(fn (PlatformSubscriptionPlan $plan): array => $this->data($plan));
    }

    /** @return array{plan: array<string, mixed>} */
    public function show(string $planId): array
    {
        $plan = PlatformSubscriptionPlan::query()
            ->where('public_id', $planId)
            ->where('status', 'active')
            ->first();

        if ($plan === null) {
            throw new ModelNotFoundException;
        }

        return ['plan' => $this->data($plan, true)];
    }

    /** @return array<string, mixed> */
    private function data(PlatformSubscriptionPlan $plan, bool $includeDescription = false): array
    {
        $decodedEntitlementKeys = json_decode(json_encode($plan->entitlement_keys, JSON_THROW_ON_ERROR), true);
        $entitlementKeys = is_array($decodedEntitlementKeys) ? array_values(array_map(static fn (mixed $key): string => (string) $key, $decodedEntitlementKeys)) : [];
        $data = [
            'id' => (string) $plan->public_id,
            'code' => (string) $plan->code,
            'name' => (string) $plan->name,
            'version' => (int) $plan->version,
            'status' => 'published',
            'availability' => 'available',
            'pricing' => ['status' => 'pricing_unavailable'],
            'entitlement_keys' => $entitlementKeys,
            'published_at' => $plan->activated_at === null ? null : CarbonImmutable::parse((string) $plan->activated_at)->toISOString(),
        ];

        if ($includeDescription) {
            $data['description'] = $plan->description;
        }

        return $data;
    }
}
