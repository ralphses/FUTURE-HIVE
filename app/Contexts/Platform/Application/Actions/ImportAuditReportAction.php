<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

use App\Contexts\Platform\Domain\Models\AuditEvent;
use App\Contexts\Platform\Domain\Models\FinancialOpeningBalanceImport;
use App\Contexts\Platform\Domain\Models\HistoricalAcademicImport;
use App\Contexts\Platform\Domain\Models\ImportBatch;
use App\Contexts\Platform\Domain\Models\ImportCommit;
use App\Contexts\Platform\Domain\Models\ImportDryRunReport;
use App\Contexts\Platform\Domain\Models\ImportDuplicateFinding;
use App\Contexts\Platform\Domain\Models\ImportMapping;
use App\Contexts\Platform\Domain\Models\ImportRecoveryAttempt;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ImportAuditReportAction
{
    private const MAX_REPORT_ROWS = 5000;

    public function __construct(private readonly SchoolPermissionChecker $permissions) {}

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function audit(Authenticatable $actor, string $school, string $import): LengthAwarePaginator
    {
        $batch = $this->authorizeAndBatch($actor, $school, $import, 'imports.audit.read');
        $events = $this->eventQuery($batch)->orderByDesc('created_at')->orderByDesc('id');
        $paginator = $events->paginate(ApiResponse::perPage(request()->integer('per_page')));

        return $this->mapPaginator($paginator);
    }

    /** @return array<string, mixed> */
    public function report(Authenticatable $actor, string $school, string $import): array
    {
        $batch = $this->authorizeAndBatch($actor, $school, $import, 'imports.report.read');
        $events = $this->eventQuery($batch)->orderBy('created_at')->orderBy('id')->limit(self::MAX_REPORT_ROWS + 1)->get();
        if ($events->count() > self::MAX_REPORT_ROWS) {
            throw ValidationException::withMessages(['report' => ['The import audit report is too large to generate synchronously.']]);
        }

        $rows = $this->rows($events);
        $byStage = [];
        $byAction = [];
        foreach ($rows as $row) {
            $byStage[$row['stage']] = ($byStage[$row['stage']] ?? 0) + 1;
            $byAction[$row['action']] = ($byAction[$row['action']] ?? 0) + 1;
        }
        ksort($byStage);
        ksort($byAction);

        return [
            'import_id' => (string) $batch->public_id,
            'template_key' => (string) $batch->template_key,
            'template_version' => (string) $batch->template_version,
            'status' => (string) $batch->status,
            'summary' => [
                'total_events' => count($rows),
                'by_stage' => $byStage,
                'by_action' => $byAction,
            ],
            'rows' => $rows,
        ];
    }

    /** @param LengthAwarePaginator<int, AuditEvent> $paginator
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function mapPaginator(LengthAwarePaginator $paginator): LengthAwarePaginator
    {
        $paginator->setCollection(collect($this->rows($paginator->getCollection())));

        return $paginator;
    }

    /** @param Collection<int, AuditEvent> $events
     * @return list<array<string, mixed>>
     */
    private function rows(Collection $events): array
    {
        $actors = DB::table('users')
            ->whereIn('id', $events->pluck('actor_id')->filter()->unique()->values())
            ->get(['id', 'public_id'])
            ->keyBy('id');

        return $events->map(function (AuditEvent $event) use ($actors): array {
            $actor = $event->actor_id === null ? null : $actors->get($event->actor_id);

            return [
                'id' => (string) $event->public_id,
                'stage' => $this->stage((string) $event->action),
                'action' => (string) $event->action,
                'subject_type' => (string) $event->subject_type,
                'subject_id' => $event->subject_public_id,
                'reason' => $event->reason,
                'state_transition' => $this->safeTransition($event->state_transition),
                'actor_id' => $actor?->public_id,
                'request_id' => $event->request_id,
                'created_at' => optional($event->created_at)->toISOString(),
            ];
        })->values()->all();
    }

    /** @return Builder<AuditEvent> */
    private function eventQuery(ImportBatch $batch): Builder
    {
        $subjects = ['import_batch' => [(string) $batch->public_id]];
        $models = [
            'import_mapping' => ImportMapping::class,
            'import_duplicate_finding' => ImportDuplicateFinding::class,
            'import_dry_run_report' => ImportDryRunReport::class,
            'import_commit' => ImportCommit::class,
            'import_recovery_attempt' => ImportRecoveryAttempt::class,
            'historical_academic_import' => HistoricalAcademicImport::class,
            'financial_opening_balance_import' => FinancialOpeningBalanceImport::class,
        ];
        foreach ($models as $type => $model) {
            $ids = $model::query()->where('import_batch_id', $batch->getKey())->pluck('public_id')->map(static fn ($id): string => (string) $id)->all();
            if ($ids !== []) {
                $subjects[$type] = $ids;
            }
        }

        return AuditEvent::query()->where('school_id', TenantContext::require()->schoolId)->where(function (Builder $query) use ($subjects): void {
            foreach ($subjects as $type => $ids) {
                $query->orWhere(fn (Builder $nested): Builder => $nested->where('subject_type', $type)->whereIn('subject_public_id', $ids));
            }
        });
    }

    private function authorizeAndBatch(Authenticatable $actor, string $school, string $import, string $permission): ImportBatch
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }

        $batch = ImportBatch::query()->where('public_id', $import)->first();
        if (! $batch instanceof ImportBatch) {
            throw new ModelNotFoundException;
        }

        return $batch;
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

    private function stage(string $action): string
    {
        return match (true) {
            str_starts_with($action, 'import.upload'), str_starts_with($action, 'import.staged'), str_starts_with($action, 'import.cancel'), str_starts_with($action, 'import.failed') => 'upload_and_staging',
            str_starts_with($action, 'import.mapping') => 'mapping',
            str_starts_with($action, 'import.validation') => 'validation',
            str_starts_with($action, 'import.duplicate'), str_starts_with($action, 'import.duplicates') => 'duplicates',
            str_starts_with($action, 'import.dry_run') => 'dry_run',
            str_starts_with($action, 'import.commit') => 'commit',
            str_starts_with($action, 'import.recovery') => 'recovery',
            str_starts_with($action, 'historical_academic') => 'historical_academic',
            str_starts_with($action, 'financial_opening_balance') => 'financial_opening_balances',
            default => 'other',
        };
    }
}
