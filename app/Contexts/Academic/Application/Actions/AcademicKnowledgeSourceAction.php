<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicKnowledgeSource;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\Contracts\PlatformPermissionResolver;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

final class AcademicKnowledgeSourceAction
{
    public function __construct(
        private readonly PlatformPermissionResolver $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function index(Authenticatable $actor, int $page = 1, int $perPage = 25): LengthAwarePaginator
    {
        $this->authorize($actor, 'academic.knowledge-sources.read');

        return AcademicKnowledgeSource::query()
            ->orderBy('source_type')
            ->orderBy('institution_reference')
            ->orderByDesc('version')
            ->orderBy('public_id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->through(fn (AcademicKnowledgeSource $source): array => $this->data($source, false));
    }

    /** @return array{source: array<string, mixed>} */
    public function show(Authenticatable $actor, string $sourceId): array
    {
        $this->authorize($actor, 'academic.knowledge-sources.read');

        return ['source' => $this->data($this->source($sourceId), true)];
    }

    /** @param array<string, mixed> $input
     * @return array{source: array<string, mixed>}
     */
    public function create(Authenticatable $actor, array $input): array
    {
        $this->authorize($actor, 'academic.knowledge-sources.manage');
        $this->validateScope($input);

        $source = DB::transaction(function () use ($actor, $input): AcademicKnowledgeSource {
            $query = AcademicKnowledgeSource::query()
                ->where('source_type', $input['source_type'])
                ->where('institution_reference', $input['institution_reference'] ?? null)
                ->lockForUpdate();
            $version = ((int) $query->max('version')) + 1;
            $source = AcademicKnowledgeSource::query()->create([
                'source_type' => $input['source_type'],
                'institution_reference' => $input['institution_reference'] ?? null,
                'label' => $input['label'],
                'description' => $input['description'] ?? null,
                'content' => $input['content'],
                'version' => $version,
                'status' => 'draft',
                'effective_at' => $input['effective_at'],
                'expires_at' => $input['expires_at'] ?? null,
                'created_by' => $actor->getAuthIdentifier(),
                'request_id' => Context::get('request_id'),
            ]);
            $this->recordAudit($actor, 'academic.knowledge_source.created', $source);

            return $source;
        });

        return ['source' => $this->data($source, true)];
    }

    /** @param array<string, mixed> $input
     * @return array{source: array<string, mixed>}
     */
    public function update(Authenticatable $actor, string $sourceId, array $input): array
    {
        $this->authorize($actor, 'academic.knowledge-sources.manage');
        $source = DB::transaction(function () use ($actor, $sourceId, $input): AcademicKnowledgeSource {
            $source = $this->source($sourceId, true);
            if ($source->status !== 'draft') {
                throw new ModelNotFoundException;
            }

            $attributes = array_intersect_key($input, array_flip(['label', 'description', 'content', 'effective_at', 'expires_at']));
            $source->update($attributes);
            $this->recordAudit($actor, 'academic.knowledge_source.updated', $source);

            return $source->refresh();
        });

        return ['source' => $this->data($source, true)];
    }

    /** @return array{source: array<string, mixed>} */
    public function lifecycle(Authenticatable $actor, string $sourceId, string $transition): array
    {
        $this->authorize($actor, 'academic.knowledge-sources.manage');
        $source = DB::transaction(function () use ($actor, $sourceId, $transition): AcademicKnowledgeSource {
            $source = $this->source($sourceId, true);
            $target = match ($transition) {
                'publish' => 'published',
                'retire' => 'retired',
                'reject' => 'rejected',
                default => throw new ModelNotFoundException,
            };

            if ($source->status === $target) {
                return $source;
            }

            $allowed = ($source->status === 'draft' && in_array($target, ['published', 'rejected'], true))
                || ($source->status === 'published' && $target === 'retired');
            if (! $allowed || ($target === 'published' && $this->hasOverlappingPublished($source))) {
                throw new ModelNotFoundException;
            }

            $from = $source->status;
            $source->update([
                'status' => $target,
                'approved_by' => $target === 'published' ? $actor->getAuthIdentifier() : $source->approved_by,
                'approved_at' => $target === 'published' ? now() : $source->approved_at,
            ]);
            $this->recordAudit($actor, 'academic.knowledge_source.'.$target, $source, ['from' => $from, 'to' => $target]);

            return $source->refresh();
        });

        return ['source' => $this->data($source, true)];
    }

    /** @return list<array<string, mixed>> */
    public function published(): array
    {
        return AcademicKnowledgeSource::query()
            ->where('status', 'published')
            ->where('effective_at', '<=', now())
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderBy('source_type')
            ->orderBy('institution_reference')
            ->orderByDesc('version')
            ->orderBy('public_id')
            ->get()
            ->map(fn (AcademicKnowledgeSource $source): array => $this->data($source, true))
            ->values()
            ->all();
    }

    private function authorize(Authenticatable $actor, string $permission): void
    {
        if (! $this->permissions->allows($actor, $permission)) {
            throw new ModelNotFoundException;
        }
    }

    private function source(string $publicId, bool $lock = false): AcademicKnowledgeSource
    {
        $query = AcademicKnowledgeSource::query()->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    /** @param array<string, mixed> $input */
    private function validateScope(array $input): void
    {
        if ($input['source_type'] === 'jamb' && ($input['institution_reference'] ?? null) !== null) {
            throw new ModelNotFoundException;
        }
        if ($input['source_type'] === 'institution' && blank($input['institution_reference'] ?? null)) {
            throw new ModelNotFoundException;
        }
    }

    private function hasOverlappingPublished(AcademicKnowledgeSource $source): bool
    {
        return AcademicKnowledgeSource::query()
            ->where('status', 'published')
            ->where('source_type', $source->source_type)
            ->where('institution_reference', $source->institution_reference)
            ->whereKeyNot($source->getKey())
            ->where('effective_at', '<=', $source->expires_at ?? now()->addYears(100))
            ->where(function (Builder $query) use ($source): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>=', $source->effective_at);
            })
            ->exists();
    }

    /** @param array<string, mixed> $transition */
    private function recordAudit(Authenticatable $actor, string $action, AcademicKnowledgeSource $source, array $transition = []): void
    {
        $this->audit->execute(new AuditEventData(
            action: $action,
            subjectType: 'academic_knowledge_source',
            subjectPublicId: (string) $source->public_id,
            actorId: (int) $actor->getAuthIdentifier(),
            requestId: Context::get('request_id'),
            stateTransition: $transition,
            metadata: [
                'source_type' => $source->source_type,
                'institution_reference' => $source->institution_reference === null ? null : 'present',
                'version' => (int) $source->version,
                'status' => $source->status,
            ],
        ));
    }

    /** @return array<string, mixed> */
    private function data(AcademicKnowledgeSource $source, bool $includeContent): array
    {
        $data = [
            'id' => (string) $source->public_id,
            'source_type' => $source->source_type,
            'institution_reference' => $source->institution_reference,
            'label' => $source->label,
            'description' => $source->description,
            'version' => (int) $source->version,
            'status' => $source->status,
            'effective_at' => $this->timestamp($source->effective_at),
            'expires_at' => $this->timestamp($source->expires_at),
            'approved_at' => $this->timestamp($source->approved_at),
        ];
        if ($includeContent) {
            $data['content'] = $this->safeContent($source->content);
        }

        return $data;
    }

    /** @return array{requirements: list<array{label: string, summary: string, subjects: list<string>}>} */
    private function safeContent(mixed $content): array
    {
        $requirements = is_array($content) && is_array($content['requirements'] ?? null) ? $content['requirements'] : [];
        $safe = [];
        foreach ($requirements as $requirement) {
            if (! is_array($requirement)) {
                continue;
            }
            $subjects = is_array($requirement['subjects'] ?? null) ? $requirement['subjects'] : [];
            $safe[] = [
                'label' => (string) ($requirement['label'] ?? ''),
                'summary' => (string) ($requirement['summary'] ?? ''),
                'subjects' => array_values(array_map('strval', $subjects)),
            ];
        }

        return ['requirements' => $safe];
    }

    private function timestamp(mixed $timestamp): ?string
    {
        return $timestamp === null ? null : CarbonImmutable::parse((string) $timestamp)->toISOString();
    }
}
