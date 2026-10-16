<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\SchoolAuditExplorerAction;
use App\Contexts\Platform\Http\Requests\SchoolAuditExplorerRequest;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SchoolAuditExplorerController
{
    #[Endpoint(operationId: 'v1.schools.audit-explorer.index', title: 'List school audit events', description: 'Lists bounded, deterministic and redacted audit events for the selected school. Raw metadata, contacts, credentials, tokens, private URLs, financial amounts, raw scores and confidential source data are excluded.')]
    #[QueryParameter(name: 'from', description: 'Inclusive start date in YYYY-MM-DD format.', type: 'string', infer: false)]
    #[QueryParameter(name: 'to', description: 'Inclusive end date in YYYY-MM-DD format.', type: 'string', infer: false)]
    #[QueryParameter(name: 'action', description: 'Exact allow-listed audit action filter.', type: 'string', infer: false)]
    #[QueryParameter(name: 'subject_type', description: 'Exact subject type filter.', type: 'string', infer: false)]
    #[QueryParameter(name: 'subject_id', description: 'Public subject UUID filter.', type: 'string', infer: false)]
    #[QueryParameter(name: 'actor', description: 'Trusted actor public UUID filter.', type: 'string', infer: false)]
    #[QueryParameter(name: 'transition', description: 'State transition value filter.', type: 'string', infer: false)]
    #[QueryParameter(name: 'request_id', description: 'Request correlation UUID filter for authorized audit readers.', type: 'string', infer: false)]
    #[Response(status: 200, description: 'A paginated safe audit explorer response.', type: 'array')]
    public function index(SchoolAuditExplorerRequest $request, string $school, SchoolAuditExplorerAction $action): JsonResponse
    {
        return ApiResponse::paginated($action->index($this->identity($request), $school, $request->validated()));
    }

    #[Endpoint(operationId: 'v1.schools.audit-explorer.show', title: 'View a school audit event', description: 'Shows one safe audit event from the selected school. The response excludes raw metadata and sensitive source values.')]
    #[Response(status: 200, description: 'A safe audit event projection.', type: 'array')]
    public function show(Request $request, string $school, string $event, SchoolAuditExplorerAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $event));
    }

    private function identity(Request $request): Authenticatable
    {
        $identity = $request->user();
        abort_unless($identity instanceof Authenticatable, 401);

        return $identity;
    }
}
