<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Platform\Domain\Models\LegalConsent;
use App\Contexts\Platform\Domain\Models\LegalPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

final class SelfServiceLegalConsentAction
{
    public function __construct(private readonly RecordAuditEventAction $audit) {}

    /** @return array{consents: list<array<string, mixed>>} */
    public function index(Authenticatable $identity): array
    {
        $consents = LegalConsent::query()
            ->join('legal_policies', 'legal_policies.id', '=', 'legal_consents.legal_policy_id')
            ->where('legal_consents.user_id', $identity->getKey())
            ->orderByDesc('legal_consents.accepted_at')
            ->orderByDesc('legal_consents.id')
            ->get(['legal_consents.public_id', 'legal_consents.accepted_at', 'legal_policies.public_id as policy_public_id', 'legal_policies.policy_type', 'legal_policies.version'])
            ->map(fn (object $consent): array => $this->consentData($consent))
            ->all();

        return ['consents' => $consents];
    }

    /** @return array{consent: array<string, mixed>} */
    public function accept(Authenticatable $identity, string $policyId): array
    {
        $consent = DB::transaction(function () use ($identity, $policyId): LegalConsent {
            $policy = LegalPolicy::query()
                ->where('public_id', $policyId)
                ->where('status', 'published')
                ->lockForUpdate()
                ->first();

            if (! $policy instanceof LegalPolicy) {
                throw new ModelNotFoundException;
            }

            $existing = LegalConsent::query()
                ->where('user_id', $identity->getKey())
                ->where('legal_policy_id', $policy->getKey())
                ->first();

            if ($existing instanceof LegalConsent) {
                return $existing;
            }

            $consent = LegalConsent::query()->create([
                'user_id' => $identity->getKey(),
                'legal_policy_id' => $policy->getKey(),
                'accepted_at' => now(),
                'request_id' => Context::get('request_id'),
            ]);

            $this->audit->execute(new AuditEventData(
                action: 'platform.legal_consent.accepted',
                subjectType: 'legal_consent',
                subjectPublicId: (string) $consent->public_id,
                actorId: (int) $identity->getAuthIdentifier(),
                requestId: Context::get('request_id'),
                metadata: ['policy_type' => $policy->policy_type, 'policy_version' => (int) $policy->version],
            ));

            return $consent;
        });

        $policy = LegalPolicy::query()->whereKey($consent->legal_policy_id)->firstOrFail();

        return ['consent' => [
            'id' => (string) $consent->public_id,
            'policy_id' => (string) $policy->public_id,
            'policy_type' => (string) $policy->policy_type,
            'version' => (int) $policy->version,
            'status' => 'accepted',
            'accepted_at' => CarbonImmutable::parse((string) $consent->accepted_at)->toISOString(),
        ]];
    }

    /** @return array<string, mixed> */
    private function consentData(object $consent): array
    {
        return [
            'id' => (string) $consent->public_id,
            'policy_id' => (string) $consent->policy_public_id,
            'policy_type' => (string) $consent->policy_type,
            'version' => (int) $consent->version,
            'status' => 'accepted',
            'accepted_at' => CarbonImmutable::parse((string) $consent->accepted_at)->toISOString(),
        ];
    }
}
