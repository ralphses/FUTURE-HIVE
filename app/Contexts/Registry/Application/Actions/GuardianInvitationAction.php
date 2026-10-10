<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Application\Actions;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Registry\Application\Contracts\GuardianInvitationCodeDelivery;
use App\Contexts\Registry\Domain\Exceptions\GuardianInvitationFailed;
use App\Contexts\Registry\Domain\Models\GuardianInvitation;
use App\Contexts\Registry\Domain\Models\StudentGuardianRelationship;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Throwable;

final class GuardianInvitationAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
        private readonly GuardianInvitationCodeDelivery $delivery,
    ) {}

    /** @return array{message: string} */
    public function request(UserIdentity $actor, string $school, string $student, string $relationship, ?string $ipAddress, ?string $userAgent): array
    {
        $this->authorize($actor, $school, 'guardian.links.manage');

        $code = (string) random_int(100000, 999999);
        $invitation = DB::transaction(function () use ($actor, $student, $relationship, $code, $ipAddress, $userAgent): GuardianInvitation {
            $record = StudentGuardianRelationship::query()
                ->where('public_id', $relationship)
                ->whereHas('student', fn ($query) => $query->where('public_id', $student))
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();
            if (! $record instanceof StudentGuardianRelationship) {
                throw new ModelNotFoundException;
            }

            GuardianInvitation::query()
                ->where('relationship_id', $record->getKey())
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revocation_reason' => 'replaced']);

            return GuardianInvitation::query()->create([
                'school_id' => TenantContext::require()->schoolId,
                'student_id' => $record->student_id,
                'guardian_profile_id' => $record->guardian_profile_id,
                'relationship_id' => $record->getKey(),
                'code_hash' => hash('sha256', $code),
                'expires_at' => now()->addSeconds((int) config('auth.guardian_invitation.challenge_ttl', 600)),
                'max_attempts' => (int) config('auth.guardian_invitation.max_attempts', 5),
                'request_ip_hash' => $this->hashMetadata($ipAddress),
                'request_user_agent_hash' => $this->hashMetadata($userAgent),
                'created_by' => $actor->getAuthIdentifier(),
            ]);
        });

        try {
            $this->delivery->send($invitation, $code);
        } catch (Throwable) {
            $invitation->forceFill(['revoked_at' => now(), 'revocation_reason' => 'delivery_unavailable'])->saveQuietly();
        }

        $this->audit->execute(new AuditEventData(
            action: 'guardian.invitation_requested',
            subjectType: 'guardian_invitation',
            subjectPublicId: (string) $invitation->public_id,
            schoolId: TenantContext::require()->schoolId,
            actorId: (int) $actor->getAuthIdentifier(),
            requestId: Context::get('request_id'),
            authorizationContext: ['permission' => 'guardian.links.manage'],
            stateTransition: ['from' => null, 'to' => 'pending'],
            requiresSchoolContext: true,
        ));

        return ['message' => 'If the relationship can be activated, an invitation code will be delivered.'];
    }

    /** @return array{active: bool, status: string} */
    public function confirm(UserIdentity $actor, string $invitationPublicId, string $code): array
    {
        $failed = false;
        $relationship = null;

        DB::transaction(function () use ($actor, $invitationPublicId, $code, &$failed, &$relationship): void {
            $invitation = GuardianInvitation::query()
                ->withoutGlobalScope('trusted_tenant')
                ->where('public_id', $invitationPublicId)
                ->lockForUpdate()
                ->first();
            $expiresAt = $invitation?->getAttribute('expires_at');
            $record = $invitation instanceof GuardianInvitation
                ? StudentGuardianRelationship::query()->withoutGlobalScope('trusted_tenant')->whereKey($invitation->relationship_id)->lockForUpdate()->first()
                : null;
            $guardianId = $record?->guardianProfile?->user_id;
            $hasVerifiedContact = $guardianId !== null && UserIdentity::query()
                ->whereKey($guardianId)
                ->whereHas('contacts', fn ($query) => $query->whereNotNull('verified_at')->whereNull('superseded_at'))
                ->exists();
            $valid = $invitation instanceof GuardianInvitation
                && $record instanceof StudentGuardianRelationship
                && $record->status === 'pending'
                && (int) $guardianId === (int) $actor->getAuthIdentifier()
                && $hasVerifiedContact
                && $invitation->consumed_at === null
                && $invitation->revoked_at === null
                && $expiresAt instanceof Carbon
                && ! $expiresAt->isPast()
                && $invitation->attempts < $invitation->max_attempts
                && hash_equals((string) $invitation->getRawOriginal('code_hash'), hash('sha256', $code));

            if (! $valid) {
                if ($invitation instanceof GuardianInvitation) {
                    $attempts = $invitation->attempts + 1;
                    $invitation->forceFill([
                        'attempts' => $attempts,
                        'revoked_at' => $attempts >= $invitation->max_attempts || ($expiresAt instanceof Carbon && $expiresAt->isPast()) ? now() : null,
                        'revocation_reason' => $attempts >= $invitation->max_attempts ? 'attempt_limit' : (($expiresAt instanceof Carbon && $expiresAt->isPast()) ? 'expired' : null),
                    ])->save();
                }
                $failed = true;

                return;
            }

            $invitation->forceFill(['consumed_at' => now()])->save();
            $record->forceFill(['status' => 'active', 'verified_at' => now()])->save();
            $relationship = $record;
        });

        if ($failed || ! $relationship instanceof StudentGuardianRelationship) {
            throw new GuardianInvitationFailed;
        }

        return ['active' => true, 'status' => 'active'];
    }

    /** @return array{message: string} */
    public function revoke(UserIdentity $actor, string $school, string $invitationPublicId, string $reason): array
    {
        $this->authorize($actor, $school, 'guardian.links.manage');

        $invitation = DB::transaction(function () use ($invitationPublicId, $reason): GuardianInvitation {
            $invitation = GuardianInvitation::query()->where('public_id', $invitationPublicId)->lockForUpdate()->first();
            if (! $invitation instanceof GuardianInvitation) {
                throw new ModelNotFoundException;
            }
            if ($invitation->consumed_at === null && $invitation->revoked_at === null) {
                $invitation->forceFill(['revoked_at' => now(), 'revocation_reason' => trim($reason)])->save();
            }

            return $invitation;
        });

        $this->audit->execute(new AuditEventData(
            action: 'guardian.invitation_revoked',
            subjectType: 'guardian_invitation',
            subjectPublicId: (string) $invitation->public_id,
            schoolId: TenantContext::require()->schoolId,
            actorId: (int) $actor->getAuthIdentifier(),
            reason: trim($reason),
            requestId: Context::get('request_id'),
            authorizationContext: ['permission' => 'guardian.links.manage'],
            stateTransition: ['to' => 'revoked'],
            requiresSchoolContext: true,
        ));

        return ['message' => 'The invitation is no longer active.'];
    }

    /**
     * @return array{items: list<array{relationship_id: string, school_id: string, student_id: string, relationship_type: string, verified_at: ?string}>}
     */
    public function activeLinks(UserIdentity $actor): array
    {
        $items = StudentGuardianRelationship::query()
            ->withoutGlobalScope('trusted_tenant')
            ->where('status', 'active')
            ->whereHas('guardianProfile', function ($query) use ($actor): void {
                $query->where('user_id', $actor->getAuthIdentifier())
                    ->whereHas('identity.contacts', fn ($contactQuery) => $contactQuery->whereNotNull('verified_at')->whereNull('superseded_at'));
            })
            ->with(['student', 'school'])
            ->get()
            ->map(fn (StudentGuardianRelationship $relationship): array => [
                'relationship_id' => (string) $relationship->public_id,
                'school_id' => (string) $relationship->school?->public_id,
                'student_id' => (string) $relationship->student?->public_id,
                'relationship_type' => (string) $relationship->relationship_type,
                'verified_at' => $this->formatTimestamp($relationship->verified_at),
            ])->values()->all();

        return ['items' => $items];
    }

    private function authorize(UserIdentity $actor, string $school, string $permission): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
    }

    private function hashMetadata(?string $value): ?string
    {
        return $value === null ? null : hash_hmac('sha256', $value, (string) config('app.key'));
    }

    private function formatTimestamp(mixed $timestamp): ?string
    {
        return $timestamp instanceof Carbon ? $timestamp->toISOString() : null;
    }
}
