<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\ImportRecoveryAction;
use App\Contexts\Platform\Http\Requests\RetryImportRequest;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ImportRecoveryController
{
    #[Endpoint(title: 'Retry failed import rows', description: 'Retries only failed rows from the latest partial import commit after explicit RETRY_FAILED confirmation. Successful, linked and skipped rows remain unchanged and are never duplicated.')]
    #[Response(status: 200, description: 'A safe import recovery result with row counts and stable error codes.', type: 'array')]
    public function retry(RetryImportRequest $request, string $school, string $import, ImportRecoveryAction $action): JsonResponse
    {
        return ApiResponse::data($action->retry($this->identity($request), $school, $import, (string) $request->validated('confirm')));
    }

    #[Endpoint(title: 'View import recovery', description: 'Returns the latest safe recovery attempt for an import commit. It excludes source values, contacts, credentials, private URLs and internal identifiers.')]
    #[Response(status: 200, description: 'The latest safe import recovery result.', type: 'array')]
    public function show(Request $request, string $school, string $import, ImportRecoveryAction $action): JsonResponse
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
