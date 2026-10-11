<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\ImportDryRunAction;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ImportDryRunController
{
    #[Endpoint(title: 'Generate an import dry run', description: 'Analyzes validated and duplicate-resolved import rows and reports planned creates, links, skips and blockers without changing school records.')]
    #[Response(status: 200, description: 'The safe dry-run impact report.', type: 'array')]
    public function generate(Request $request, string $school, string $import, ImportDryRunAction $action): JsonResponse
    {
        return ApiResponse::data($action->generate($this->identity($request), $school, $import));
    }

    #[Endpoint(title: 'View an import dry run', description: 'Returns the latest safe impact report for a staged import. It excludes raw row values, contacts, credentials and internal identifiers.')]
    #[Response(status: 200, description: 'The latest dry-run impact report.', type: 'array')]
    public function show(Request $request, string $school, string $import, ImportDryRunAction $action): JsonResponse
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
