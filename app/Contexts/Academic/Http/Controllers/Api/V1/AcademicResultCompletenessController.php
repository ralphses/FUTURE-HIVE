<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Controllers\Api\V1;

use App\Contexts\Academic\Application\Actions\AcademicResultCompletenessAction;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Header;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AcademicResultCompletenessController
{
    #[Endpoint(
        title: 'Check result completeness',
        description: 'Checks whether the selected academic term has the approved submissions, active rosters and explicit score states required for result computation. This read-only check never changes academic records.',
    )]
    #[Header(name: 'X-Request-ID', description: 'Request correlation identifier.', type: 'string', format: 'uuid', required: true, status: '*')]
    public function __invoke(Request $request, string $school, string $session, string $term, AcademicResultCompletenessAction $action): JsonResponse
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return ApiResponse::data($action->execute($identity, $school, $session, $term));
    }
}
