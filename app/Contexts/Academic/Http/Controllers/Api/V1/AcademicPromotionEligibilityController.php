<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Controllers\Api\V1;

use App\Contexts\Academic\Application\Actions\AcademicPromotionEligibilityAction;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AcademicPromotionEligibilityController
{
    #[Endpoint(title: 'List promotion eligibility', description: 'Returns a read-only, leadership-scoped summary of promotion-rule outcomes for active students in a term. It never creates promotion decisions or changes enrolments.')]
    #[Response(status: 200, description: 'Paginated promotion eligibility summaries.', type: 'array')]
    public function index(Request $request, string $school, string $session, string $term, AcademicPromotionEligibilityAction $action): JsonResponse
    {
        return ApiResponse::paginated($action->index($this->identity($request), $school, $session, $term));
    }

    #[Endpoint(title: 'View student promotion eligibility', description: 'Returns safe criterion outcomes for one active student using published result snapshots and an active promotion rule. No automatic promotion or repetition occurs.')]
    #[Response(status: 200, description: 'Promotion eligibility summary.', type: 'array')]
    public function student(Request $request, string $school, string $session, string $term, string $student, AcademicPromotionEligibilityAction $action): JsonResponse
    {
        return ApiResponse::data($action->student($this->identity($request), $school, $session, $term, $student));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
