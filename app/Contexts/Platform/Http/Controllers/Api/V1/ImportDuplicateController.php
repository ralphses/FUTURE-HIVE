<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\ImportDuplicateDetectionAction;
use App\Contexts\Platform\Http\Requests\ResolveImportDuplicateRequest;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ImportDuplicateController
{
    #[Endpoint(title: 'Detect import duplicates', description: 'Finds exact duplicate candidates within a validated import and against existing records in the selected school. No canonical records are changed.')]
    #[Response(status: 200, description: 'The duplicate-finding summary.', type: 'array')]
    public function detect(Request $request, string $school, string $import, ImportDuplicateDetectionAction $action): JsonResponse
    {
        return ApiResponse::data($action->detect($this->identity($request), $school, $import));
    }

    #[Endpoint(title: 'View import duplicates', description: 'Lists safe duplicate candidates and whether each finding has an explicit resolution. Raw contacts and staged cells are never returned.')]
    #[Response(status: 200, description: 'The duplicate-finding summary.', type: 'array')]
    public function show(Request $request, string $school, string $import, ImportDuplicateDetectionAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $import));
    }

    #[Endpoint(title: 'Resolve an import duplicate', description: 'Records an explicit create-new, link-existing or skip decision for a server-discovered duplicate candidate. It does not mutate canonical records.')]
    #[Response(status: 200, description: 'The resolved duplicate finding.', type: 'array')]
    public function resolve(ResolveImportDuplicateRequest $request, string $school, string $import, string $duplicate, ImportDuplicateDetectionAction $action): JsonResponse
    {
        return ApiResponse::data($action->resolve($this->identity($request), $school, $import, $duplicate, $request->validated('resolution'), $request->validated('candidate_public_id')));
    }

    private function identity(Request $request): Authenticatable
    {
        $identity = $request->user();
        abort_unless($identity instanceof Authenticatable, 401);

        return $identity;
    }
}
