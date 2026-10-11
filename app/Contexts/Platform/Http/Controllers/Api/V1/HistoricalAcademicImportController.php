<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\HistoricalAcademicImportAction;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class HistoricalAcademicImportController
{
    #[Endpoint(title: 'Archive historical academic results', description: 'Archives validated historical academic rows as unverified provenance. The archive does not affect current scores, calculations, readiness or published reports.')]
    #[Response(status: 200, description: 'A safe historical import status and row outcome summary.', type: 'array')]
    public function store(Request $request, string $school, string $import, HistoricalAcademicImportAction $action): JsonResponse
    {
        return ApiResponse::data($action->import($this->identity($request), $school, $import));
    }

    #[Endpoint(title: 'View historical academic import', description: 'Returns safe status, counts and row outcome codes for the historical archive without exposing source values or internal identifiers.')]
    #[Response(status: 200, description: 'The safe historical import result.', type: 'object')]
    public function show(Request $request, string $school, string $import, HistoricalAcademicImportAction $action): JsonResponse
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
