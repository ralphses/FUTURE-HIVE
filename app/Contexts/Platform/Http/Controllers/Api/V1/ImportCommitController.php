<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\ImportCommitAction;
use App\Contexts\Platform\Http\Requests\CommitImportRequest;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ImportCommitController
{
    #[Endpoint(title: 'Commit a prepared import', description: 'Creates only the validated and explicitly resolved records in this staged import. Confirm with COMMIT. Repeated requests return the existing safe result and never create duplicate records.')]
    #[Response(status: 200, description: 'A safe import commit report containing counts and row outcomes.', type: 'array')]
    public function commit(CommitImportRequest $request, string $school, string $import, ImportCommitAction $action): JsonResponse
    {
        return ApiResponse::data($action->commit($this->identity($request), $school, $import, (string) $request->validated('confirm')));
    }

    #[Endpoint(title: 'View an import commit', description: 'Returns the safe result of the latest commit attempt. It excludes source values, contacts, credentials, private URLs and internal identifiers.')]
    #[Response(status: 200, description: 'The safe import commit result.', type: 'array')]
    public function show(Request $request, string $school, string $import, ImportCommitAction $action): JsonResponse
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
