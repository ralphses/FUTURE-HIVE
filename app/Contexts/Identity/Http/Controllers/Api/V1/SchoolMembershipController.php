<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Http\Controllers\Api\V1;

use App\Contexts\Identity\Application\Actions\ListSchoolMembershipsAction;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

final class SchoolMembershipController
{
    /**
     * @response array{data: array<int, array{public_id: string, school: array{public_id: string, name: string}, is_owner: bool, joined_at: string|null}>}
     */
    public function index(ListSchoolMembershipsAction $memberships): JsonResponse
    {
        $identity = request()->user();

        abort_unless($identity instanceof UserIdentity, 401);

        return ApiResponse::data([
            'memberships' => $memberships->handle($identity)->map(static function (SchoolMembership $membership): array {
                $joinedAt = $membership->getAttribute('joined_at');

                return [
                    'public_id' => $membership->public_id,
                    'school' => [
                        'public_id' => $membership->school->public_id,
                        'name' => $membership->school->name,
                    ],
                    'is_owner' => $membership->is_owner,
                    'joined_at' => $joinedAt instanceof Carbon ? $joinedAt->toISOString() : null,
                ];
            })->all(),
        ]);
    }
}
