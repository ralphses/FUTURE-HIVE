<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\ImportMappingAction;
use App\Contexts\Platform\Http\Requests\UpdateImportMappingRequest;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ImportMappingController
{
    #[Endpoint(title: 'View an import mapping', description: 'Returns the latest saved mapping for a staged import. Required fields that are not mapped are listed without exposing private file data.')]
    #[Response(status: 200, description: 'The saved mapping and required-field warnings.', type: 'array')]
    public function show(Request $request, string $school, string $import, ImportMappingAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $import));
    }

    #[Endpoint(title: 'Save an import mapping', description: 'Creates a new immutable mapping version for a staged import. Only source columns and target fields from the approved template may be used; this does not validate or import records.')]
    #[Response(status: 201, description: 'The saved mapping version.', type: 'array')]
    public function replace(UpdateImportMappingRequest $request, string $school, string $import, ImportMappingAction $action): JsonResponse
    {
        return ApiResponse::data($action->replace($this->identity($request), $school, $import, $request->validated('mappings')), 201);
    }

    #[Endpoint(title: 'Preview mapped import rows', description: 'Shows a bounded preview of staged rows using the latest mapping. Row numbers are retained, contact values are redacted and no school records are changed.')]
    #[Response(status: 200, description: 'The mapped staged-row preview.', type: 'array')]
    public function preview(Request $request, string $school, string $import, ImportMappingAction $action): JsonResponse
    {
        return ApiResponse::data($action->preview($this->identity($request), $school, $import));
    }

    private function identity(Request $request): Authenticatable
    {
        $identity = $request->user();
        abort_unless($identity instanceof Authenticatable, 401);

        return $identity;
    }
}
