<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Platform\Domain\Models\AiConsent;
use App\Contexts\Platform\Domain\Models\AiConsentEvent;
use App\Contexts\Platform\Domain\Models\LegalPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

final class AiConsentAction
{
    private const PURPOSE = 'academic_assistance';

    /** @var list<string> */
    private const FEATURE_SCOPES = ['career_guidance', 'academic_insights', 'lesson_planning'];

    public function __construct(private readonly RecordAuditEventAction $audit) {}

    /** @return array{consents: list<array<string, mixed>>} */
    public function index(Authenticatable $identity): array
    {
        $consents = AiConsent::query()
            ->join('legal_policies', 'legal_policies.id', '=', 'ai_consents.legal_policy_id')
            ->where('ai_consents.user_id', $identity->getKey())
            ->orderByDesc('ai_consents.accepted_at')
            ->orderByDesc('ai_consents.id')
            ->get(['ai_consents.public_id', 'ai_consents.purpose', 'ai_consents.feature_scope', 'ai_consents.status', 'ai_consents.accepted_at', 'ai_consents.expires_at', 'ai_consents.revoked_at', 'legal_policies.public_id as policy_public_id', 'legal_policies.version'])
            ->map(fn (object $consent): array => $this->data($consent))
            ->all();

        return ['consents' => $consents];
    }

    /** @param array{policy_id: string, purpose: string, feature_scope: string} $input
     * @return array{consent: array<string, mixed>}
     */
    public function accept(Authenticatable $identity, array $input): array
    {
        $this->validateInput($input);
        $consent = DB::transaction(function () use ($identity, $input): AiConsent {
            $policy = LegalPolicy::query()
                ->where('public_id', $input['policy_id'])
                ->where('policy_type', 'ai_privacy')
                ->where('status', 'published')
                ->where(function ($query): void {
                    $query->whereNull('effective_at')->orWhere('effective_at', '<=', now());
                })
                ->lockForUpdate()
                ->first();
            if (! $policy instanceof LegalPolicy) {
                throw new ModelNotFoundException;
            }

            $existing = AiConsent::query()
                ->where('user_id', $identity->getKey())
                ->where('legal_policy_id', $policy->getKey())
                ->where('purpose', $input['purpose'])
                ->where('feature_scope', $input['feature_scope'])
                ->where('status', 'active')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();
            if ($existing instanceof AiConsent) {
                return $existing;
            }

            $acceptedAt = now();
            $consent = AiConsent::query()->create([
                'user_id' => $identity->getKey(),
                'legal_policy_id' => $policy->getKey(),
                'purpose' => $input['purpose'],
                'feature_scope' => $input['feature_scope'],
                'status' => 'active',
                'accepted_at' => $acceptedAt,
                'expires_at' => $acceptedAt->copy()->addDays(365),
                'request_id' => Context::get('request_id'),
            ]);
            $this->event($consent, $identity, 'accepted');
            $this->audit->execute(new AuditEventData(
                action: 'platform.ai_consent.accepted',
                subjectType: 'ai_consent',
                subjectPublicId: (string) $consent->public_id,
                actorId: (int) $identity->getAuthIdentifier(),
                requestId: Context::get('request_id'),
                metadata: ['purpose' => $consent->purpose, 'feature_scope' => $consent->feature_scope, 'policy_version' => (int) $policy->version],
            ));

            return $consent;
        });

        return ['consent' => $this->freshData($consent)];
    }

    /** @return array{consent: array<string, mixed>} */
    public function revoke(Authenticatable $identity, string $publicId): array
    {
        $consent = DB::transaction(function () use ($identity, $publicId): AiConsent {
            $consent = AiConsent::query()->where('public_id', $publicId)->where('user_id', $identity->getKey())->lockForUpdate()->first();
            if (! $consent instanceof AiConsent) {
                throw new ModelNotFoundException;
            }
            if ($consent->status !== 'revoked') {
                $consent->update(['status' => 'revoked', 'revoked_at' => now()]);
                $this->event($consent, $identity, 'revoked');
                $this->audit->execute(new AuditEventData(
                    action: 'platform.ai_consent.revoked',
                    subjectType: 'ai_consent',
                    subjectPublicId: (string) $consent->public_id,
                    actorId: (int) $identity->getAuthIdentifier(),
                    requestId: Context::get('request_id'),
                    metadata: ['purpose' => $consent->purpose, 'feature_scope' => $consent->feature_scope],
                ));
            }

            return $consent->refresh();
        });

        return ['consent' => $this->freshData($consent)];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{allowed: bool, payload: array<string, mixed>, issue_code: string|null, provider_status: string}
     */
    public function prepare(Authenticatable $identity, string $featureScope, array $payload, bool $sensitive): array
    {
        $safePayload = $this->safePayload($payload);
        if (! $sensitive) {
            return ['allowed' => true, 'payload' => $safePayload, 'issue_code' => null, 'provider_status' => 'deterministic_local_minimized_payload'];
        }

        $latest = AiConsent::query()->where('user_id', $identity->getKey())->where('purpose', self::PURPOSE)->where('feature_scope', $featureScope)->latest('accepted_at')->first();
        if (! $latest instanceof AiConsent) {
            return ['allowed' => false, 'payload' => [], 'issue_code' => 'consent_required', 'provider_status' => 'deterministic_local_consent_required'];
        }
        if ($latest->status === 'revoked') {
            return ['allowed' => false, 'payload' => [], 'issue_code' => 'consent_revoked', 'provider_status' => 'deterministic_local_consent_revoked'];
        }
        if ($latest->status !== 'active') {
            return ['allowed' => false, 'payload' => [], 'issue_code' => 'consent_required', 'provider_status' => 'deterministic_local_consent_required'];
        }
        if (CarbonImmutable::parse((string) $latest->expires_at)->isPast()) {
            return ['allowed' => false, 'payload' => [], 'issue_code' => 'consent_expired', 'provider_status' => 'deterministic_local_consent_expired'];
        }

        return ['allowed' => true, 'payload' => $safePayload, 'issue_code' => null, 'provider_status' => 'deterministic_local_minimized_payload'];
    }

    /** @param array{policy_id: string, purpose: string, feature_scope: string} $input */
    private function validateInput(array $input): void
    {
        if ($input['purpose'] !== self::PURPOSE || ! in_array($input['feature_scope'], self::FEATURE_SCOPES, true)) {
            throw new ModelNotFoundException;
        }
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function safePayload(array $payload): array
    {
        $allowed = ['career_interest', 'subject_interest', 'institution_reference', 'published_result_count', 'attendance_register_count', 'attendance_recorded_count', 'assessment_submission_count', 'subject', 'level', 'class_arm', 'topic', 'objective_focus'];

        return array_intersect_key($payload, array_flip($allowed));
    }

    private function event(AiConsent $consent, Authenticatable $identity, string $event): void
    {
        AiConsentEvent::query()->create(['ai_consent_id' => $consent->getKey(), 'user_id' => $identity->getKey(), 'event' => $event, 'request_id' => Context::get('request_id'), 'occurred_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function freshData(AiConsent $consent): array
    {
        $policy = LegalPolicy::query()->findOrFail($consent->legal_policy_id);

        return $this->data((object) ['public_id' => $consent->public_id, 'purpose' => $consent->purpose, 'feature_scope' => $consent->feature_scope, 'status' => $consent->status, 'accepted_at' => $consent->accepted_at, 'expires_at' => $consent->expires_at, 'revoked_at' => $consent->revoked_at, 'policy_public_id' => $policy->public_id, 'version' => $policy->version]);
    }

    /** @param object{public_id: string, purpose: string, feature_scope: string, status: string, accepted_at: mixed, expires_at: mixed, revoked_at: mixed, policy_public_id: string, version: int} $consent
     * @return array<string, mixed>
     */
    private function data(object $consent): array
    {
        return ['id' => (string) $consent->public_id, 'policy_id' => (string) $consent->policy_public_id, 'policy_version' => (int) $consent->version, 'purpose' => (string) $consent->purpose, 'feature_scope' => (string) $consent->feature_scope, 'status' => (string) $consent->status, 'accepted_at' => CarbonImmutable::parse((string) $consent->accepted_at)->toISOString(), 'expires_at' => CarbonImmutable::parse((string) $consent->expires_at)->toISOString(), 'revoked_at' => $consent->revoked_at === null ? null : CarbonImmutable::parse((string) $consent->revoked_at)->toISOString()];
    }
}
