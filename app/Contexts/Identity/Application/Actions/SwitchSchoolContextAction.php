<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\Actions;

use App\Contexts\Identity\Application\DTOs\SchoolContext;
use App\Contexts\Identity\Domain\Models\AuthSession;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class SwitchSchoolContextAction
{
    public function handle(UserIdentity $identity, AuthSession $session, string $schoolPublicId): SchoolContext
    {
        return DB::transaction(function () use ($identity, $session, $schoolPublicId): SchoolContext {
            $lockedSession = AuthSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $membership = SchoolMembership::query()
                ->with('school')
                ->where('user_id', $identity->id)
                ->where('status', 'active')
                ->whereHas('school', static fn ($query) => $query->where('public_id', $schoolPublicId)->where('status', 'active'))
                ->first();

            if (! $membership instanceof SchoolMembership || $lockedSession->user_id !== $identity->id) {
                throw (new ModelNotFoundException)->setModel(SchoolMembership::class);
            }

            $lockedSession->update(['active_school_membership_id' => $membership->id]);
            $session->setAttribute('active_school_membership_id', $membership->id);

            return new SchoolContext($membership);
        });
    }
}
