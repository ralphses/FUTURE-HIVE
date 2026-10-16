<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\SelfServiceLegalConsentAction;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SelfServiceLegalConsentController
{
    #[Endpoint(operationId: 'v1.me.legal-consents.index', title: 'List my legal consents', description: 'Lists the authenticated identity’s exact legal-policy versions accepted through self-service. Contact values, tokens and internal identifiers are excluded.')]
    #[Response(status: 200, description: 'Safe legal-consent projection.', type: 'array')]
    public function index(Request $request, SelfServiceLegalConsentAction $action): JsonResponse
    {
        return ApiResponse::data($action->index($this->identity($request)));
    }

    #[Endpoint(operationId: 'v1.me.legal-consents.store', title: 'Accept a legal policy', description: 'Records acceptance of one exact published legal-policy version for the authenticated identity. The server controls the actor and acceptance timestamp, and repeated acceptance is idempotent.')]
    #[Response(status: 200, description: 'Recorded or existing legal-consent projection.', type: 'array')]
    public function store(Request $request, SelfServiceLegalConsentAction $action): JsonResponse
    {
        $validated = $request->validate(['policy' => ['required', 'uuid']]);

        return ApiResponse::data($action->accept($this->identity($request), (string) $validated['policy']));
    }

    private function identity(Request $request): Authenticatable
    {
        $identity = $request->user();
        abort_unless($identity instanceof Authenticatable, 401);

        return $identity;
    }
}
