<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Platform\Domain\Models\OperationalReportSchedule;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

final class OperationalReportScheduleAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return list<array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>> */
    public function index(Authenticatable $actor, string $school): array
    {
        $context = $this->authorize($actor, $school, 'operations.reports.schedules.read');

        return OperationalReportSchedule::query()->where('school_id', $context->schoolId)->orderBy('report_type')->orderBy('id')->get()->map(fn (OperationalReportSchedule $schedule): array => $this->data($schedule))->all();
    }

    /**
     * @param  array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>  $input
     * @return array{schedule: array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>}
     */
    public function create(Authenticatable $actor, string $school, array $input): array
    {
        $context = $this->authorize($actor, $school, 'operations.reports.schedules.manage');
        $schedule = DB::transaction(function () use ($actor, $context, $input): OperationalReportSchedule {
            $hash = $this->hash((string) $input['idempotency_key'], $context->schoolId);
            $existing = OperationalReportSchedule::query()->where('school_id', $context->schoolId)->where('idempotency_key_hash', $hash)->lockForUpdate()->first();
            if ($existing instanceof OperationalReportSchedule) {
                return $existing;
            }
            [$sessionId, $termId] = $this->parents($context->schoolId, $input);

            $schedule = OperationalReportSchedule::query()->create([
                'school_id' => $context->schoolId,
                'report_type' => $input['report'],
                'format' => $input['format'],
                'academic_session_id' => $sessionId,
                'academic_term_id' => $termId,
                'from_date' => $input['from'] ?? null,
                'to_date' => $input['to'] ?? null,
                'frequency' => $input['frequency'],
                'status' => 'draft',
                'created_by' => $actor->getKey(),
                'idempotency_key_hash' => $hash,
                'request_id' => Context::get('request_id'),
            ]);
            $this->audit($actor, $context->schoolId, 'operations.report_schedule.created', $schedule);

            return $schedule;
        });

        return ['schedule' => $this->data($schedule)];
    }

    /**
     * @param  array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>  $input
     * @return array{schedule: array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>}
     */
    public function update(Authenticatable $actor, string $school, string $id, array $input): array
    {
        $schedule = DB::transaction(function () use ($actor, $school, $id, $input): OperationalReportSchedule {
            $schedule = $this->record($actor, $school, $id, 'operations.reports.schedules.manage', true);
            if ($schedule->status !== 'draft') {
                throw new ModelNotFoundException;
            }
            $schedule->update(array_filter([
                'format' => $input['format'] ?? null,
                'frequency' => $input['frequency'] ?? null,
                'from_date' => $input['from'] ?? null,
                'to_date' => $input['to'] ?? null,
                'updated_by' => $actor->getKey(),
            ], static fn (mixed $value): bool => $value !== null));

            return $schedule;
        });
        $this->audit($actor, (int) $schedule->school_id, 'operations.report_schedule.updated', $schedule);

        return ['schedule' => $this->data($schedule)];
    }

    /** @return array{schedule: array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>} */
    public function lifecycle(Authenticatable $actor, string $school, string $id, string $transition): array
    {
        $schedule = DB::transaction(function () use ($actor, $school, $id, $transition): OperationalReportSchedule {
            $schedule = $this->record($actor, $school, $id, 'operations.reports.schedules.manage', true);
            $to = match ($transition) {
                'activate' => 'active', 'pause' => 'paused', 'retire' => 'retired', default => throw new ModelNotFoundException,
            };
            if ($schedule->status === $to) {
                return $schedule;
            }
            $allowed = ['draft' => ['active'], 'active' => ['paused', 'retired'], 'paused' => ['active', 'retired']];
            if (! in_array($to, $allowed[$schedule->status] ?? [], true)) {
                throw new ModelNotFoundException;
            }
            $schedule->update([
                'status' => $to,
                'next_run_at' => $to === 'active' ? $this->nextRunAt($schedule->frequency) : null,
                'updated_by' => $actor->getKey(),
            ]);

            return $schedule;
        });
        $this->audit($actor, (int) $schedule->school_id, 'operations.report_schedule.'.($schedule->status === 'active' ? 'activated' : $schedule->status), $schedule);

        return ['schedule' => $this->data($schedule)];
    }

    private function record(Authenticatable $actor, string $school, string $id, string $permission, bool $lock = false): OperationalReportSchedule
    {
        $context = $this->authorize($actor, $school, $permission);
        $query = OperationalReportSchedule::query()->where('school_id', $context->schoolId)->where('public_id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function authorize(Authenticatable $actor, string $school, string $permission): TenantContext
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }

        return $context;
    }

    /** @param array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>> $input
     * @return array{0: int|null, 1: int|null}
     */
    private function parents(int $schoolId, array $input): array
    {
        $session = isset($input['session']) ? DB::table('academic_sessions')->where('school_id', $schoolId)->where('public_id', $input['session'])->where('status', 'active')->first() : null;
        $term = isset($input['term']) ? DB::table('academic_terms')->where('school_id', $schoolId)->where('academic_session_id', $session?->id)->where('public_id', $input['term'])->where('status', 'active')->first() : null;
        if ((isset($input['session']) && $session === null) || (isset($input['term']) && $term === null)) {
            throw new ModelNotFoundException;
        }

        return [$session?->id, $term?->id];
    }

    /** @return array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>> */
    private function data(OperationalReportSchedule $schedule): array
    {
        return ['id' => (string) $schedule->public_id, 'report' => (string) $schedule->report_type, 'format' => (string) $schedule->format, 'frequency' => (string) $schedule->frequency, 'status' => (string) $schedule->status, 'session' => $schedule->academic_session_id === null ? null : DB::table('academic_sessions')->where('id', $schedule->academic_session_id)->value('public_id'), 'term' => $schedule->academic_term_id === null ? null : DB::table('academic_terms')->where('id', $schedule->academic_term_id)->value('public_id'), 'from' => $schedule->from_date === null ? null : CarbonImmutable::parse((string) $schedule->from_date)->toDateString(), 'to' => $schedule->to_date === null ? null : CarbonImmutable::parse((string) $schedule->to_date)->toDateString(), 'next_run_at' => $schedule->next_run_at === null ? null : CarbonImmutable::parse((string) $schedule->next_run_at)->toISOString(), 'created_at' => $schedule->created_at === null ? null : CarbonImmutable::parse((string) $schedule->created_at)->toISOString(), 'updated_at' => $schedule->updated_at === null ? null : CarbonImmutable::parse((string) $schedule->updated_at)->toISOString()];
    }

    private function nextRunAt(string $frequency): CarbonImmutable
    {
        return match ($frequency) {
            'daily' => CarbonImmutable::now()->addDay(),
            'weekly' => CarbonImmutable::now()->addWeek(),
            'monthly' => CarbonImmutable::now()->addMonth(),
            default => throw new ModelNotFoundException,
        };
    }

    private function audit(Authenticatable $actor, int $schoolId, string $action, OperationalReportSchedule $schedule): void
    {
        $this->audit->execute(new AuditEventData(
            action: $action,
            subjectType: 'operational_report_schedule',
            subjectPublicId: (string) $schedule->public_id,
            schoolId: $schoolId,
            actorId: (int) $actor->getAuthIdentifier(),
            requestId: Context::get('request_id'),
            metadata: ['report' => $schedule->report_type, 'format' => $schedule->format, 'status' => $schedule->status],
            requiresSchoolContext: true,
        ));
    }

    private function hash(string $key, int $schoolId): string
    {
        return hash_hmac('sha256', 'operational-report-schedule|'.$schoolId.'|'.$key, (string) config('app.key'));
    }
}
