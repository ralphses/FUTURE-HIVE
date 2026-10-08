<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\Actions;

use App\Contexts\Identity\Application\DTOs\SchoolInvitationIssue;
use App\Contexts\Identity\Domain\Enums\ContactType;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolInvitation;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserContact;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Identity\Domain\Services\ContactCanonicalizer;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateSchoolInvitationAction
{
    public function __construct(
        private readonly ContactCanonicalizer $canonicalizer,
        private readonly ResolveSchoolPermissionsAction $permissions,
    ) {}

    public function handle(UserIdentity $inviter, string $schoolPublicId, string $contactValue): SchoolInvitationIssue
    {
        $school = School::query()
            ->where('public_id', $schoolPublicId)
            ->where('status', 'active')
            ->first();

        if (! $school instanceof School || ! $this->permissions->allows($inviter, $schoolPublicId, 'school.memberships.invite')) {
            $this->notFound();
        }

        $contact = $this->findVerifiedContact($contactValue);

        if (! $contact instanceof UserContact) {
            throw ValidationException::withMessages([
                'contact' => 'The contact cannot receive a school invitation.',
            ]);
        }

        $invitee = $contact->identity()->first();

        if (! $invitee instanceof UserIdentity) {
            throw ValidationException::withMessages([
                'contact' => 'The contact cannot receive a school invitation.',
            ]);
        }

        if (SchoolMembership::query()
            ->where('school_id', $school->id)
            ->where('user_id', $invitee->id)
            ->where('status', 'active')
            ->exists()) {
            throw ValidationException::withMessages([
                'contact' => 'The identity is already a school member.',
            ]);
        }

        if (SchoolInvitation::query()
            ->where('school_id', $school->id)
            ->where('invitee_contact_id', $contact->id)
            ->where('status', 'pending')
            ->exists()) {
            throw ValidationException::withMessages([
                'contact' => 'A school invitation is already pending for this contact.',
            ]);
        }

        $token = bin2hex(random_bytes(32));
        $invitation = DB::transaction(fn (): SchoolInvitation => SchoolInvitation::create([
            'school_id' => $school->id,
            'inviter_id' => $inviter->id,
            'invitee_id' => $invitee->id,
            'invitee_contact_id' => $contact->id,
            'token_hash' => hash('sha256', $token),
            'status' => 'pending',
            'expires_at' => now()->addDays((int) config('auth.school_invitations.ttl_days', 7)),
        ]));

        return new SchoolInvitationIssue($invitation, $token);
    }

    private function findVerifiedContact(string $value): ?UserContact
    {
        foreach ([ContactType::Email, ContactType::Phone] as $type) {
            try {
                $canonical = $this->canonicalizer->canonicalize($type, $value);
            } catch (\InvalidArgumentException) {
                continue;
            }

            $contact = UserContact::query()
                ->where('type', $type->value)
                ->where('canonical_value', $canonical)
                ->whereNull('superseded_at')
                ->whereNotNull('verified_at')
                ->first();

            if ($contact instanceof UserContact) {
                return $contact;
            }
        }

        return null;
    }

    private function notFound(): never
    {
        throw (new ModelNotFoundException)->setModel(School::class);
    }
}
