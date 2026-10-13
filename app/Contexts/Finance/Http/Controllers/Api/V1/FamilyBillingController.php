<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Http\Controllers\Api\V1;

use App\Contexts\Finance\Application\Actions\FamilyBillingAction;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FamilyBillingController
{
    #[Endpoint(title: 'List family billing', description: 'Lists safe school-scoped consolidated invoice projections for authorized finance staff.')]
    public function index(Request $request, string $school, FamilyBillingAction $action): JsonResponse
    {
        return ApiResponse::data($action->forSchool($this->identity($request), $school));
    }

    #[Endpoint(title: 'Show guardian family billing', description: 'Shows safe consolidated billing for a selected guardian within the trusted school scope.')]
    public function show(Request $request, string $school, string $guardian, FamilyBillingAction $action): JsonResponse
    {
        return ApiResponse::data($action->forSchool($this->identity($request), $school, $guardian));
    }

    #[Endpoint(title: 'Show my family billing', description: 'Shows safe billing for students linked to the authenticated verified guardian in the selected school.')]
    public function me(Request $request, FamilyBillingAction $action): JsonResponse
    {
        return ApiResponse::data($action->forGuardian($this->identity($request)));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
