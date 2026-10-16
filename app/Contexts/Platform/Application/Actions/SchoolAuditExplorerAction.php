<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

use App\Contexts\Platform\Domain\Models\AuditEvent;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SchoolAuditExplorerAction
{
    private const MAX_PERIOD_DAYS = 31;

    public function __construct(private readonly SchoolPermissionChecker $permissions) {}

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function index(Authenticatable $actor, string $school, array $filters): LengthAwarePaginator
    {
        $this->authorize($actor, $school);
        $query = $this->query($filters);
        $paginator = $query->paginate(ApiResponse::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null));

        return $this->map($paginator);
    }

    /** @return array<string, mixed> */
    public function show(Authenticatable $actor, string $school, string $event): array
    {
        $this->authorize($actor, $school);
        $record = AuditEvent::query()
            ->where('school_id', TenantContext::require()->schoolId)
            ->where('public_id', $event)
            ->first();
        if (! $record instanceof AuditEvent) {
            throw new ModelNotFoundException;
        }

        return $this->data($record, $this->actors(collect([$record])));
    }

    /** @param array<string, mixed> $filters
     * @return Builder<AuditEvent>
     */
    private function query(array $filters): Builder
    {
        $query = AuditEvent::query()->where('school_id', TenantContext::require()->schoolId);
        foreach (['action' => 'action', 'subject_type' => 'subject_type', 'subject_public_id' => 'subject_id', 'request_id' => 'request_id'] as $column => $filter) {
            if (isset($filters[$filter]) && $filters[$filter] !== '') {
                $query->where($column, (string) $filters[$filter]);
            }
        }
        if (isset($filters['actor']) && $filters['actor'] !== '') {
            $actorId = DB::table('users')->where('public_id', (string) $filters['actor'])->value('id');
            $query->where('actor_id', $actorId ?? 0);
        }
        if (isset($filters['transition']) && $filters['transition'] !== '') {
            $query->where(function (Builder $nested) use ($filters): void {
                $transition = (string) $filters['transition'];
                $nested->whereJsonContains('state_transition->from', $transition)
                    ->orWhereJsonContains('state_transition->to', $transition)
                    ->orWhereJsonContains('state_transition->status', $transition);
            });
        }
        $this->applyDateFilters($query, $filters);

        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /** @param Builder<AuditEvent> $query
     * @param  array<string, mixed>  $filters
     */
    private function applyDateFilters(Builder $query, array $filters): void
    {
        if (! isset($filters['from'], $filters['to'])) {
            return;
        }

        $from = CarbonImmutable::createFromFormat('Y-m-d', (string) $filters['from'])->startOfDay();
        $to = CarbonImmutable::createFromFormat('Y-m-d', (string) $filters['to'])->endOfDay();
        if ($from->greaterThan($to) || $from->diffInDays($to) > self::MAX_PERIOD_DAYS) {
            throw ValidationException::withMessages(['date_range' => ['The audit date range must be ordered and bounded to 31 days.']]);
        }

        $query->whereBetween('created_at', [$from, $to]);
    }

    /** @param LengthAwarePaginator<int, AuditEvent> $paginator
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function map(LengthAwarePaginator $paginator): LengthAwarePaginator
    {
        $actors = $this->actors($paginator->getCollection());
        $paginator->setCollection($paginator->getCollection()->map(fn (AuditEvent $event): array => $this->data($event, $actors)));

        return $paginator;
    }

    /** @param Collection<int, AuditEvent> $events
     * @return Collection<int|string, \stdClass>
     */
    private function actors(Collection $events): Collection
    {
        return DB::table('users')->whereIn('id', $events->pluck('actor_id')->filter()->unique()->values())->get(['id', 'public_id'])->keyBy('id');
    }

    /** @param Collection<int|string, \stdClass> $actors
     * @return array<string, mixed>
     */
    private function data(AuditEvent $event, Collection $actors): array
    {
        $actor = $event->actor_id === null ? null : $actors->get($event->actor_id);

        return [
            'id' => (string) $event->public_id,
            'action' => (string) $event->action,
            'subject_type' => (string) $event->subject_type,
            'subject_id' => $event->subject_public_id,
            'reason' => $event->reason,
            'state_transition' => $this->safeTransition($event->state_transition),
            'actor_id' => $actor?->public_id,
            'request_id' => $event->request_id,
            'created_at' => optional($event->created_at)->toISOString(),
        ];
    }

    /** @return array<string, scalar|null> */
    private function safeTransition(mixed $transition): array
    {
        if (! is_array($transition)) {
            return [];
        }

        $safe = [];
        foreach (['from', 'to', 'status', 'version', 'change_type', 'resolution'] as $key) {
            if (array_key_exists($key, $transition) && (is_scalar($transition[$key]) || $transition[$key] === null)) {
                $safe[$key] = $transition[$key];
            }
        }

        return $safe;
    }

    private function authorize(Authenticatable $actor, string $school): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, 'operations.audit.read')) {
            throw new ModelNotFoundException;
        }
    }
}
