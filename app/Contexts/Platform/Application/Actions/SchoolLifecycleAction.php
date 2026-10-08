<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\SchoolLifecycleContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SchoolLifecycleAction
{
    /** @var list<string> */
    private const ALLOWED_STATUSES = ['active', 'suspended', 'archived'];

    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return array{school_id: string, status: string} */
    public function show(Authenticatable $actor, SchoolLifecycleContext $context): array
    {
        $this->authorize($actor, $context, 'school.lifecycle.read');

        return [
            'school_id' => $context->schoolPublicId,
            'status' => $this->currentStatus($context),
        ];
    }

    /** @return array{school_id: string, status: string, transition: array{from: string, to: string, changed: bool}} */
    public function transition(
        Authenticatable $actor,
        SchoolLifecycleContext $context,
        string $targetStatus,
        string $reason,
    ): array {
        $this->authorize($actor, $context, 'school.lifecycle.manage');

        if (! in_array($targetStatus, self::ALLOWED_STATUSES, true) || trim($reason) === '') {
            throw new RuntimeException('The school lifecycle transition is invalid.');
        }

        return DB::transaction(function () use ($actor, $context, $targetStatus, $reason): array {
            $school = DB::table('schools')->where('id', $context->schoolId)->lockForUpdate()->first();

            if ($school === null || (string) $school->public_id !== $context->schoolPublicId) {
                throw new ModelNotFoundException;
            }

            $from = (string) $school->status;
            $changed = $this->isAllowedTransition($from, $targetStatus);

            if (! $changed && $from !== $targetStatus) {
                throw new RuntimeException('The school lifecycle transition is invalid.');
            }

            if ($changed) {
                DB::table('schools')->where('id', $context->schoolId)->update([
                    'status' => $targetStatus,
                    'updated_at' => now(),
                ]);

                $this->audit->execute(new AuditEventData(
                    action: 'school.lifecycle_changed',
                    subjectType: 'school',
                    subjectPublicId: $context->schoolPublicId,
                    schoolId: $context->schoolId,
                    actorId: (int) $actor->getAuthIdentifier(),
                    reason: trim($reason),
                    requestId: Context::get('request_id'),
                    authorizationContext: ['permission' => 'school.lifecycle.manage'],
                    stateTransition: ['from' => $from, 'to' => $targetStatus],
                    requiresSchoolContext: true,
                ));
            }

            return [
                'school_id' => $context->schoolPublicId,
                'status' => $targetStatus,
                'transition' => ['from' => $from, 'to' => $targetStatus, 'changed' => $changed],
            ];
        });
    }

    private function authorize(Authenticatable $actor, SchoolLifecycleContext $context, string $permission): void
    {
        if (! $this->permissions->allowsLifecycle($actor, $context->schoolPublicId, $permission)) {
            throw new ModelNotFoundException;
        }
    }

    private function currentStatus(SchoolLifecycleContext $context): string
    {
        $status = DB::table('schools')->where('id', $context->schoolId)->value('status');

        if (! is_string($status) || ! in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new ModelNotFoundException;
        }

        return $status;
    }

    private function isAllowedTransition(string $from, string $to): bool
    {
        return match ($to) {
            'suspended' => $from === 'active',
            'active' => $from === 'suspended',
            'archived' => in_array($from, ['active', 'suspended'], true),
            default => false,
        };
    }
}
