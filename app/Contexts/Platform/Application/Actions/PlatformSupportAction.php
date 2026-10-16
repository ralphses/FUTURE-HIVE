<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Platform\Domain\Models\PlatformSupportAccessGrant;
use App\Contexts\Platform\Domain\Models\PlatformSupportCase;
use App\Support\Http\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PlatformSupportAction
{
    /**
     * @param  array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>  $filters
     * @return LengthAwarePaginator<int, array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>>
     */
    public function cases(Authenticatable $actor, array $filters): LengthAwarePaginator
    {
        $this->authorize($actor, 'platform.support-cases.read');
        $query = PlatformSupportCase::query()->orderByDesc('created_at')->orderByDesc('id');
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['school'])) {
            $schoolId = $this->schoolId((string) $filters['school']);
            $query->where('school_id', $schoolId);
        }

        return $query->paginate(ApiResponse::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null))->through(fn (PlatformSupportCase $case): array => $this->caseData($case));
    }

    /** @return array{case: array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>} */
    public function showCase(Authenticatable $actor, string $caseId): array
    {
        $this->authorize($actor, 'platform.support-cases.read');

        return ['case' => $this->caseData($this->supportCase($caseId))];
    }

    /**
     * @param  array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>  $input
     * @return array{case: array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>}
     */
    public function createCase(Authenticatable $actor, string $school, array $input): array
    {
        $this->authorize($actor, 'platform.support-cases.manage');
        $schoolId = $this->activeSchoolId($school);
        $case = DB::transaction(function () use ($actor, $schoolId, $input): PlatformSupportCase {
            $case = PlatformSupportCase::query()->create([
                'school_id' => $schoolId,
                'subject' => trim((string) $input['subject']),
                'category' => $input['category'],
                'severity' => $input['severity'],
                'status' => 'open',
                'reason_code' => $input['reason_code'] ?? null,
                'created_by' => $actor->getAuthIdentifier(),
                'request_id' => Context::get('request_id'),
            ]);
            $this->audit($actor, 'platform.support_case.created', 'platform_support_case', (string) $case->public_id, $schoolId, ['to' => 'open']);

            return $case;
        });

        return ['case' => $this->caseData($case)];
    }

    /** @return array{case: array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>} */
    public function transitionCase(Authenticatable $actor, string $caseId, string $status): array
    {
        $this->authorize($actor, 'platform.support-cases.manage');
        $case = DB::transaction(function () use ($actor, $caseId, $status): PlatformSupportCase {
            $case = $this->supportCase($caseId, true);
            if ($case->status === $status) {
                return $case;
            }
            $allowed = [
                'open' => ['in_progress', 'cancelled'],
                'in_progress' => ['waiting_on_school', 'resolved', 'cancelled'],
                'waiting_on_school' => ['in_progress', 'cancelled'],
                'resolved' => ['closed', 'in_progress'],
                'closed' => [],
                'cancelled' => [],
            ];
            if (! in_array($status, $allowed[$case->status] ?? [], true)) {
                throw new ModelNotFoundException;
            }
            $from = $case->status;
            $case->update([
                'status' => $status,
                'updated_by' => $actor->getAuthIdentifier(),
                'closed_by' => $status === 'closed' ? $actor->getAuthIdentifier() : $case->closed_by,
                'closed_at' => $status === 'closed' ? now() : $case->closed_at,
            ]);
            $this->audit($actor, 'platform.support_case.status_changed', 'platform_support_case', (string) $case->public_id, (int) $case->school_id, ['from' => $from, 'to' => $status]);

            return $case;
        });

        return ['case' => $this->caseData($case)];
    }

    /** @return array{access_grants: list<array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>>} */
    public function grants(Authenticatable $actor, string $caseId): array
    {
        $this->authorize($actor, 'platform.support-access.read');
        $case = $this->supportCase($caseId);

        return ['access_grants' => PlatformSupportAccessGrant::query()->where('support_case_id', $case->getKey())->orderByDesc('created_at')->orderByDesc('id')->get()->map(fn (PlatformSupportAccessGrant $grant): array => $this->grantData($grant))->all()];
    }

    /**
     * @param  array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>  $input
     * @return array{access_grant: array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>}
     */
    public function requestGrant(Authenticatable $actor, string $caseId, array $input): array
    {
        $this->authorize($actor, 'platform.support-access.request');
        $requestKey = (string) $input['idempotency_key'];
        $case = $this->supportCase($caseId);
        if (in_array($case->status, ['closed', 'cancelled'], true)) {
            throw new ModelNotFoundException;
        }
        $fingerprint = hash_hmac('sha256', json_encode(['case' => $case->public_id, 'purpose' => trim((string) $input['purpose']), 'scope' => $input['scope'], 'expires_at' => $input['expires_at']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), (string) config('app.key'));
        $keyHash = hash_hmac('sha256', 'platform-support-access|'.$requestKey, (string) config('app.key'));
        $grant = DB::transaction(function () use ($actor, $case, $input, $fingerprint, $keyHash): PlatformSupportAccessGrant {
            $existing = PlatformSupportAccessGrant::query()->where('support_case_id', $case->getKey())->where('idempotency_key_hash', $keyHash)->lockForUpdate()->first();
            if ($existing instanceof PlatformSupportAccessGrant) {
                if (! hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
                    throw ValidationException::withMessages(['idempotency_key' => ['The idempotency key was already used for a different request.']]);
                }

                return $existing;
            }
            $grant = PlatformSupportAccessGrant::query()->create([
                'school_id' => $case->school_id,
                'support_case_id' => $case->getKey(),
                'purpose' => trim((string) $input['purpose']),
                'scope' => $input['scope'],
                'status' => 'requested',
                'requested_by' => $actor->getAuthIdentifier(),
                'requested_at' => now(),
                'expires_at' => $input['expires_at'],
                'idempotency_key_hash' => $keyHash,
                'request_fingerprint' => $fingerprint,
                'request_id' => Context::get('request_id'),
            ]);
            $this->audit($actor, 'platform.support_access.requested', 'platform_support_access_grant', (string) $grant->public_id, (int) $case->school_id, ['to' => 'requested']);

            return $grant;
        });

        return ['access_grant' => $this->grantData($grant)];
    }

    /** @return array{access_grant: array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>} */
    public function approveGrant(Authenticatable $actor, string $grantId): array
    {
        $this->authorize($actor, 'platform.support-access.approve');
        $grant = DB::transaction(function () use ($actor, $grantId): PlatformSupportAccessGrant {
            $grant = $this->grant($grantId, true);
            if ((int) $grant->requested_by === (int) $actor->getAuthIdentifier()) {
                throw new ModelNotFoundException;
            }
            if ($grant->status === 'approved') {
                return $grant;
            }
            if ($grant->status !== 'requested' || CarbonImmutable::parse((string) $grant->expires_at)->isPast()) {
                throw new ModelNotFoundException;
            }
            $grant->update(['status' => 'approved', 'approved_by' => $actor->getAuthIdentifier(), 'approved_at' => now()]);
            $this->audit($actor, 'platform.support_access.approved', 'platform_support_access_grant', (string) $grant->public_id, (int) $grant->school_id, ['from' => 'requested', 'to' => 'approved']);

            return $grant;
        });

        return ['access_grant' => $this->grantData($grant)];
    }

    /** @return array{access_grant: array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>} */
    public function revokeGrant(Authenticatable $actor, string $grantId): array
    {
        $this->authorize($actor, 'platform.support-access.revoke');
        $grant = DB::transaction(function () use ($actor, $grantId): PlatformSupportAccessGrant {
            $grant = $this->grant($grantId, true);
            if ($grant->status === 'revoked') {
                return $grant;
            }
            if ($grant->status !== 'approved') {
                throw new ModelNotFoundException;
            }
            $grant->update(['status' => 'revoked', 'revoked_by' => $actor->getAuthIdentifier(), 'revoked_at' => now()]);
            $this->audit($actor, 'platform.support_access.revoked', 'platform_support_access_grant', (string) $grant->public_id, (int) $grant->school_id, ['from' => 'approved', 'to' => 'revoked']);

            return $grant;
        });

        return ['access_grant' => $this->grantData($grant)];
    }

    private function authorize(Authenticatable $actor, string $permission): void
    {
        $allowed = DB::table('platform_role_assignments')
            ->join('roles', 'roles.id', '=', 'platform_role_assignments.role_id')
            ->join('role_permissions', 'role_permissions.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('platform_role_assignments.user_id', $actor->getAuthIdentifier())
            ->whereNull('platform_role_assignments.revoked_at')
            ->where('roles.scope', 'platform')->where('roles.is_active', true)
            ->where('permissions.scope', 'platform')->where('permissions.is_active', true)
            ->where('permissions.key', $permission)->exists();
        if (! $allowed) {
            throw new ModelNotFoundException;
        }
    }

    private function activeSchoolId(string $publicId): int
    {
        $school = DB::table('schools')->where('public_id', $publicId)->where('status', 'active')->first();
        if ($school === null) {
            throw new ModelNotFoundException;
        }

        return (int) $school->id;
    }

    private function schoolId(string $publicId): int
    {
        $school = DB::table('schools')->where('public_id', $publicId)->first();
        if ($school === null) {
            throw new ModelNotFoundException;
        }

        return (int) $school->id;
    }

    private function supportCase(string $publicId, bool $lock = false): PlatformSupportCase
    {
        $query = PlatformSupportCase::query()->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function grant(string $publicId, bool $lock = false): PlatformSupportAccessGrant
    {
        $query = PlatformSupportAccessGrant::query()->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    /** @return array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>> */
    private function caseData(PlatformSupportCase $case): array
    {
        return ['id' => (string) $case->public_id, 'school' => $this->schoolPublicId((int) $case->school_id), 'subject' => (string) $case->subject, 'category' => (string) $case->category, 'severity' => (string) $case->severity, 'status' => (string) $case->status, 'reason_code' => $case->reason_code, 'created_at' => $this->timestamp($case->created_at), 'updated_at' => $this->timestamp($case->updated_at), 'closed_at' => $this->timestamp($case->closed_at)];
    }

    /** @return array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>> */
    private function grantData(PlatformSupportAccessGrant $grant): array
    {
        $status = (string) $grant->status;
        if ($status === 'approved' && CarbonImmutable::parse((string) $grant->expires_at)->isPast()) {
            $status = 'expired';
        }

        return ['id' => (string) $grant->public_id, 'case' => (string) $this->case($grant->support_case_id)->public_id, 'school' => $this->schoolPublicId((int) $grant->school_id), 'purpose' => (string) $grant->purpose, 'scope' => (string) $grant->scope, 'status' => $status, 'requested_at' => $this->timestamp($grant->requested_at), 'approved_at' => $this->timestamp($grant->approved_at), 'expires_at' => $this->timestamp($grant->expires_at), 'revoked_at' => $this->timestamp($grant->revoked_at)];
    }

    private function case(int $id): PlatformSupportCase
    {
        return PlatformSupportCase::query()->whereKey($id)->firstOrFail();
    }

    private function schoolPublicId(int $schoolId): string
    {
        return (string) DB::table('schools')->where('id', $schoolId)->value('public_id');
    }

    /** @param array<string, string> $transition */
    private function audit(Authenticatable $actor, string $action, string $subjectType, string $subjectPublicId, int $schoolId, array $transition = []): void
    {
        app(RecordAuditEventAction::class)->execute(new AuditEventData(action: $action, subjectType: $subjectType, subjectPublicId: $subjectPublicId, schoolId: $schoolId, actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), stateTransition: $transition));
    }

    private function timestamp(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->toISOString();
    }
}
