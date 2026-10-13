<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Controllers\Api\V1;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Application\Actions\ParentReportCardAction;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ParentReportCardController
{
    #[Endpoint(title: 'Show parent report cards', description: 'Shows the current official published report card for each active verified linked student using the active matching report-card template. Historical versions and PDF downloads remain outside this endpoint.')]
    #[Response(status: 200, description: 'Paginated authorized parent report cards.', type: 'array')]
    public function index(Request $request, ParentReportCardAction $action): JsonResponse
    {
        return ApiResponse::paginated($action->index($this->identity($request)));
    }

    #[Endpoint(title: 'Show child report card', description: 'Shows the current official published report card for one active verified linked student. Unrelated, revoked and cross-school students fail generically.')]
    public function child(Request $request, string $student, ParentReportCardAction $action): JsonResponse
    {
        return ApiResponse::data($action->child($this->identity($request), $student));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
