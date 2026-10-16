<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

use App\Contexts\Platform\Application\Contracts\PlatformPermissionResolver;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Platform\Domain\Models\AiEvaluationResult;
use App\Contexts\Platform\Domain\Models\AiEvaluationReview;
use App\Contexts\Platform\Domain\Models\AiEvaluationRun;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

final class AiEvaluationAction
{
    /** @var list<string> */
    private const FEATURES = ['ADV-001', 'ADV-002', 'ADV-003', 'ADV-004'];

    /** @var list<string> */
    private const FIXTURE_SETS = ['approved_safety_v1', 'known_failure_safety_v1'];

    /** @var list<string> */
    private const DIMENSIONS = ['accuracy', 'source_grounding', 'bias', 'refusal', 'hallucination', 'privacy_redaction'];

    public function __construct(
        private readonly PlatformPermissionResolver $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function index(Authenticatable $actor, array $filters): LengthAwarePaginator
    {
        $this->authorize($actor, 'platform.ai-evaluations.read');

        $query = AiEvaluationRun::query()->orderByDesc('created_at')->orderByDesc('id');
        if (isset($filters['feature_key'])) {
            $query->where('feature_key', $filters['feature_key']);
        }
        if (isset($filters['state'])) {
            $query->where('state', $filters['state']);
        }

        return $query->paginate($this->perPage($filters['per_page'] ?? null))->through(fn (AiEvaluationRun $run): array => $this->data($run));
    }

    /** @return array{evaluation: array<string, mixed>} */
    public function show(Authenticatable $actor, string $publicId): array
    {
        $this->authorize($actor, 'platform.ai-evaluations.read');
        $run = $this->findRun($publicId);

        return ['evaluation' => $this->detail($run)];
    }

    /** @param array{feature_key: string, fixture_set: string, fixture_version: string, idempotency_key: string} $input
     * @return array{evaluation: array<string, mixed>}
     */
    public function create(Authenticatable $actor, array $input): array
    {
        $this->authorize($actor, 'platform.ai-evaluations.manage');
        $this->validateInput($input);
        $fingerprint = hash('sha256', json_encode([$input['feature_key'], $input['fixture_set'], $input['fixture_version']], JSON_THROW_ON_ERROR));
        $keyHash = hash_hmac('sha256', 'ai-evaluation|'.(string) $input['idempotency_key'], (string) config('app.key'));
        $run = DB::transaction(function () use ($actor, $input, $fingerprint, $keyHash): AiEvaluationRun {
            $existing = AiEvaluationRun::query()->where('idempotency_key_hash', $keyHash)->lockForUpdate()->first();
            if ($existing instanceof AiEvaluationRun) {
                if (! hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
                    throw new ModelNotFoundException;
                }

                return $existing;
            }

            $run = AiEvaluationRun::query()->create([
                'feature_key' => $input['feature_key'],
                'fixture_set' => $input['fixture_set'],
                'fixture_version' => $input['fixture_version'],
                'state' => 'draft',
                'review_state' => 'pending',
                'release_gate_status' => 'pending',
                'requested_by' => $actor->getAuthIdentifier(),
                'idempotency_key_hash' => $keyHash,
                'request_fingerprint' => $fingerprint,
                'request_id' => Context::get('request_id'),
            ]);
            $this->audit->execute(new AuditEventData(
                action: 'platform.ai_evaluation.created',
                subjectType: 'ai_evaluation_run',
                subjectPublicId: (string) $run->public_id,
                actorId: (int) $actor->getAuthIdentifier(),
                requestId: Context::get('request_id'),
                metadata: ['feature_key' => $run->feature_key, 'fixture_set' => $run->fixture_set, 'fixture_version' => $run->fixture_version],
            ));

            return $run;
        });

        return ['evaluation' => $this->detail($run)];
    }

    /** @return array{evaluation: array<string, mixed>} */
    public function run(Authenticatable $actor, string $publicId): array
    {
        $this->authorize($actor, 'platform.ai-evaluations.manage');
        $run = DB::transaction(function () use ($actor, $publicId): AiEvaluationRun {
            $run = $this->findRun($publicId, true);
            if (in_array($run->state, ['completed', 'blocked', 'cancelled'], true)) {
                return $run;
            }
            if ($run->state !== 'draft') {
                throw new ModelNotFoundException;
            }

            $run->update(['state' => 'running', 'started_at' => now(), 'provider_status' => 'deterministic_local_fake']);
            $failed = $run->fixture_set === 'known_failure_safety_v1';
            foreach (self::DIMENSIONS as $dimension) {
                $score = $failed && in_array($dimension, ['accuracy', 'bias', 'hallucination'], true) ? 72 : 98;
                AiEvaluationResult::query()->create([
                    'evaluation_run_id' => $run->getKey(),
                    'dimension' => $dimension,
                    'status' => $score >= 90 ? 'passed' : 'failed',
                    'score' => $score,
                    'issue_code' => $score >= 90 ? null : 'threshold_not_met',
                    'evidence_label' => 'sanitized_fixture_aggregate',
                ]);
            }
            $run->update([
                'state' => $failed ? 'blocked' : 'completed',
                'release_gate_status' => $failed ? 'failed' : 'passed',
                'issue_codes' => $failed ? ['evaluation_threshold_failed'] : [],
                'metrics' => ['dimensions' => count(self::DIMENSIONS), 'passed' => $failed ? 3 : 6, 'failed' => $failed ? 3 : 0],
                'completed_at' => now(),
            ]);
            $this->audit->execute(new AuditEventData(
                action: 'platform.ai_evaluation.completed',
                subjectType: 'ai_evaluation_run',
                subjectPublicId: (string) $run->public_id,
                actorId: (int) $actor->getAuthIdentifier(),
                requestId: Context::get('request_id'),
                metadata: ['state' => $run->state, 'release_gate_status' => $run->release_gate_status, 'issue_codes' => $run->issue_codes],
            ));

            return $run->refresh();
        });

        return ['evaluation' => $this->detail($run)];
    }

    /** @return array{evaluation: array<string, mixed>} */
    public function review(Authenticatable $actor, string $publicId, string $decision, string $reason): array
    {
        $this->authorize($actor, 'platform.ai-evaluations.review');
        $run = DB::transaction(function () use ($actor, $publicId, $decision, $reason): AiEvaluationRun {
            $run = $this->findRun($publicId, true);
            if ((int) $run->requested_by === (int) $actor->getAuthIdentifier()) {
                throw new ModelNotFoundException;
            }
            if ($run->review_state === $decision) {
                return $run;
            }
            if ($run->review_state !== 'pending' || ! in_array($decision, ['approved', 'rejected'], true) || $run->state !== 'completed' || ($decision === 'approved' && $run->release_gate_status !== 'passed')) {
                throw new ModelNotFoundException;
            }

            AiEvaluationReview::query()->create([
                'evaluation_run_id' => $run->getKey(),
                'decision' => $decision,
                'reason' => trim($reason),
                'reviewer_id' => $actor->getAuthIdentifier(),
                'request_id' => Context::get('request_id'),
            ]);
            $run->update(['review_state' => $decision, 'reviewed_by' => $actor->getAuthIdentifier(), 'reviewed_at' => now()]);
            $this->audit->execute(new AuditEventData(
                action: 'platform.ai_evaluation.reviewed',
                subjectType: 'ai_evaluation_run',
                subjectPublicId: (string) $run->public_id,
                actorId: (int) $actor->getAuthIdentifier(),
                requestId: Context::get('request_id'),
                metadata: ['decision' => $decision, 'release_gate_status' => $run->release_gate_status],
            ));

            return $run->refresh();
        });

        return ['evaluation' => $this->detail($run)];
    }

    private function authorize(Authenticatable $actor, string $permission): void
    {
        if (! $this->permissions->allows($actor, $permission)) {
            throw new ModelNotFoundException;
        }
    }

    /** @param array<string, mixed> $input */
    private function validateInput(array $input): void
    {
        $feature = $input['feature_key'] ?? null;
        $fixtureSet = $input['fixture_set'] ?? null;
        $fixtureVersion = $input['fixture_version'] ?? null;

        if (! is_string($feature) || ! in_array($feature, self::FEATURES, true)
            || ! is_string($fixtureSet) || ! in_array($fixtureSet, self::FIXTURE_SETS, true)
            || ! is_string($fixtureVersion) || trim($fixtureVersion) === '') {
            throw new ModelNotFoundException;
        }
    }

    private function findRun(string $publicId, bool $lock = false): AiEvaluationRun
    {
        $query = AiEvaluationRun::query()->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function detail(AiEvaluationRun $run): array
    {
        $results = AiEvaluationResult::query()->where('evaluation_run_id', $run->getKey())->orderBy('dimension')->get()->map(fn (AiEvaluationResult $result): array => [
            'id' => (string) $result->public_id,
            'dimension' => $result->dimension,
            'status' => $result->status,
            'score' => $result->score,
            'issue_code' => $result->issue_code,
            'evidence' => $result->evidence_label,
        ])->all();

        return $this->data($run) + ['results' => $results];
    }

    /** @return array<string, mixed> */
    private function data(AiEvaluationRun $run): array
    {
        return [
            'id' => (string) $run->public_id,
            'feature_key' => $run->feature_key,
            'fixture_version' => $run->fixture_version,
            'state' => $run->state,
            'review_state' => $run->review_state,
            'release_gate_status' => $run->release_gate_status,
            'provider_status' => $run->provider_status,
            'issue_codes' => $run->issue_codes ?? [],
            'metrics' => $run->metrics,
            'started_at' => $this->timestamp($run->started_at),
            'completed_at' => $this->timestamp($run->completed_at),
            'reviewed_at' => $this->timestamp($run->reviewed_at),
            'created_at' => $this->timestamp($run->created_at),
        ];
    }

    private function timestamp(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->toISOString();
    }

    private function perPage(mixed $value): int
    {
        return min(max((int) ($value ?? 25), 1), 100);
    }
}
