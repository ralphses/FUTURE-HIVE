<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\Actions;

use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Database\Eloquent\Collection;

final class ListSchoolMembershipsAction
{
    /** @return Collection<int, SchoolMembership> */
    public function handle(UserIdentity $identity): Collection
    {
        return SchoolMembership::query()
            ->with('school')
            ->where('user_id', $identity->id)
            ->where('status', 'active')
            ->whereHas('school', static fn ($query) => $query->where('status', 'active'))
            ->orderBy('id')
            ->get();
    }
}
