<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Academic\Application\Actions\AcademicReadinessAction;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Header;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AcademicReadinessController
{
    #[Endpoint(
        title: 'Check academic readiness',
        description: 'Checks whether the selected school has the academic periods, structure, subject offerings, assessment policies and grading scales needed for future assessment capture. This read-only check never creates or changes academic data.',
    )]
    #[Header(name: 'X-Request-ID', description: 'Request correlation identifier.', type: 'string', format: 'uuid', required: true, status: '*')]
    public function __invoke(Request $request, string $school, AcademicReadinessAction $action): JsonResponse
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return ApiResponse::data($action->execute($identity, $school));
    }
}
