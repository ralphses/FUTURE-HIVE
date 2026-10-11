<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\ImportValidationAction;
use App\Contexts\Platform\Http\Requests\ValidateImportRequest;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ImportValidationController
{
    #[Endpoint(title: 'Validate an import', description: 'Validates and normalizes mapped staged rows without creating or changing school records. Invalid rows retain safe field-level errors.')]
    #[Response(status: 200, description: 'The validation summary and bounded row results.', type: 'array')]
    public function validate(ValidateImportRequest $request, string $school, string $import, ImportValidationAction $action): JsonResponse
    {
        return ApiResponse::data($action->validate($this->identity($request), $school, $import));
    }

    #[Endpoint(title: 'View import validation', description: 'Returns the latest validation and normalization results for a mapped staged import. Contacts are redacted and canonical school records are unchanged.')]
    #[Response(status: 200, description: 'The validation summary and bounded row results.', type: 'array')]
    public function show(Request $request, string $school, string $import, ImportValidationAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $import));
    }

    private function identity(Request $request): Authenticatable
    {
        $identity = $request->user();
        abort_unless($identity instanceof Authenticatable, 401);

        return $identity;
    }
}
