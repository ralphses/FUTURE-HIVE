<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\Actions;

use App\Contexts\Identity\Application\DTOs\BreakGlassGrantData;
use App\Contexts\Identity\Domain\Models\BreakGlassAccessGrant;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Support\Observability\SensitiveDataRedactor;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateBreakGlassGrantAction
{
    public function __construct(
        private readonly ResolvePlatformPermissionsAction $permissions,
        private readonly RecordAuditEventAction $audit,
        private readonly SensitiveDataRedactor $redactor,
    ) {}

    public function handle(UserIdentity $actor, BreakGlassGrantData $data): BreakGlassAccessGrant
    {
        if (! $this->permissions->allows($actor, 'platform.grants.create')) {
            $this->notFound();
        }

        $school = School::query()->whereKey($data->schoolId)->where('status', 'active')->first();
        $now = now();
        $maxExpiry = $now->copy()->addMinutes((int) config('auth.platform.break_glass_max_minutes', 60));

        if (! $school instanceof School || trim($data->resourceScope) === '' || trim($data->purpose) === '' || $data->approvalContext === [] || $data->expiresAt <= $now || $data->expiresAt > $maxExpiry) {
            throw ValidationException::withMessages(['grant' => 'The break-glass grant is invalid.']);
        }

        return DB::transaction(function () use ($actor, $data, $school): BreakGlassAccessGrant {
            $grant = BreakGlassAccessGrant::query()->create([
                'granted_to_user_id' => $data->targetUserId,
                'granted_by_user_id' => $actor->id,
                'school_id' => $school->id,
                'resource_scope' => trim($data->resourceScope),
                'purpose' => trim($data->purpose),
                'approval_context' => $this->redactor->redact($data->approvalContext),
                'request_id' => $data->requestId,
                'expires_at' => $data->expiresAt,
            ]);

            $this->audit->execute(new AuditEventData(
                action: 'platform.break_glass_grant_created',
                subjectType: BreakGlassAccessGrant::class,
                subjectPublicId: $grant->public_id,
                schoolId: $school->id,
                actorId: $actor->id,
                reason: $data->purpose,
                requestId: $data->requestId,
                authorizationContext: ['permission' => 'platform.grants.create', 'resource_scope' => $grant->resource_scope],
                metadata: ['expires_at' => $data->expiresAt->format(DATE_ATOM)],
                requiresSchoolContext: true,
            ));

            return $grant;
        });
    }

    private function notFound(): never
    {
        throw (new ModelNotFoundException)->setModel(BreakGlassAccessGrant::class);
    }
}
