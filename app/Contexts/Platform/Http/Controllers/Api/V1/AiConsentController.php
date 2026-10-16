<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\AiConsentAction;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AiConsentController
{
    #[Endpoint(operationId: 'v1.me.ai-consents.index', title: 'List my AI consents', description: 'Lists safe AI-assistance consent scopes and exact policy versions for the authenticated identity. Raw consent values and sensitive student data are excluded.')]
    #[Response(status: 200, description: 'AI consent projections.', type: 'array')]
    public function index(Request $request, AiConsentAction $action): JsonResponse
    {
        return ApiResponse::data($action->index($this->identity($request)));
    }

    #[Endpoint(operationId: 'v1.me.ai-consents.store', title: 'Accept AI assistance consent', description: 'Records consent for one exact published AI privacy policy version and bounded feature scope. The server controls identity, actor, timestamps and retention.')]
    #[Response(status: 201, description: 'AI consent projection.', type: 'array')]
    public function store(Request $request, AiConsentAction $action): JsonResponse
    {
        $validated = $request->validate(['policy_id' => ['required', 'uuid'], 'purpose' => ['required', 'string', 'in:academic_assistance'], 'feature_scope' => ['required', 'string', 'in:career_guidance,academic_insights,lesson_planning']]);

        return ApiResponse::data($action->accept($this->identity($request), $validated), 201);
    }

    #[Endpoint(operationId: 'v1.me.ai-consents.revoke', title: 'Revoke AI assistance consent', description: 'Revokes one authenticated identity-owned AI consent scope and prevents future sensitive provider payloads for that scope.')]
    #[Response(status: 200, description: 'Revoked AI consent projection.', type: 'array')]
    public function revoke(Request $request, string $consent, AiConsentAction $action): JsonResponse
    {
        return ApiResponse::data($action->revoke($this->identity($request), $consent));
    }

    private function identity(Request $request): Authenticatable
    {
        $identity = $request->user();
        abort_unless($identity instanceof Authenticatable, 401);

        return $identity;
    }
}
