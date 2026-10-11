<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\ImportBatchAction;
use App\Contexts\Platform\Http\Requests\UploadImportRequest;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ImportBatchController
{
    #[Endpoint(title: 'Upload and stage an import file', description: 'Uploads a CSV or Excel file that matches an approved template, scans and stores it privately, then stages its rows without changing school records.')]
    #[Response(status: 201, description: 'The staged import metadata.', type: 'array')]
    public function store(UploadImportRequest $request, string $school, ImportBatchAction $action): JsonResponse
    {
        return ApiResponse::data($action->upload($this->identity($request), $school, (string) $request->validated('template'), (string) $request->validated('version'), $request->file('file')), 201);
    }

    #[Endpoint(title: 'List import batches', description: 'Lists upload and staging metadata for the selected school. Row contents are not returned; later slices own mapping and validation previews.')]
    #[Response(status: 200, description: 'A paginated list of safe import batch metadata.', type: 'array')]
    public function index(Request $request, string $school, ImportBatchAction $action): JsonResponse
    {
        return ApiResponse::paginated($action->index($this->identity($request), $school));
    }

    #[Endpoint(title: 'View an import batch', description: 'Returns safe metadata for one upload and its staging result. It does not expose private file URLs or staged cell contents.')]
    public function show(Request $request, string $school, string $import, ImportBatchAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $import));
    }

    #[Endpoint(title: 'Cancel an import batch', description: 'Cancels an uploaded or staged batch while retaining its history. Cancellation does not change students, guardians, staff or memberships.')]
    public function cancel(Request $request, string $school, string $import, ImportBatchAction $action): JsonResponse
    {
        return ApiResponse::data($action->cancel($this->identity($request), $school, $import));
    }

    private function identity(Request $request): Authenticatable
    {
        $identity = $request->user();
        abort_unless($identity instanceof Authenticatable, 401);

        return $identity;
    }
}
