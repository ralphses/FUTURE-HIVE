<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Controllers\Api\V1;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Application\Actions\ParentCommunicationAction;
use App\Contexts\Registry\Http\Requests\ParentCommunicationMessageRequest;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SchoolCommunicationController
{
    #[Endpoint(title: 'List school communications', description: 'Lists safe communication threads within the authenticated staff member’s trusted school and assignment scope.')] #[Response(status: 200, description: 'Authorized communication threads.', type: 'array')]
    public function index(Request $request, string $school, ParentCommunicationAction $action): JsonResponse
    {
        return ApiResponse::data($action->staffIndex($this->identity($request), $school));
    }

    #[Endpoint(title: 'View school communication', description: 'Returns one communication thread within the trusted school and authorized staff scope.')] #[Response(status: 200, description: 'The communication thread.', type: 'array')]
    public function show(Request $request, string $school, string $thread, ParentCommunicationAction $action): JsonResponse
    {
        return ApiResponse::data($action->staffThread($this->identity($request), $school, $thread));
    }

    #[Endpoint(title: 'Reply to school communication', description: 'Adds an immutable bounded staff message to an open authorized thread. No external delivery is performed.')] #[Response(status: 201, description: 'The message.', type: 'array')]
    public function message(ParentCommunicationMessageRequest $request, string $school, string $thread, ParentCommunicationAction $action): JsonResponse
    {
        return ApiResponse::data($action->staffMessage($this->identity($request), $school, $thread, (string) $request->validated('body')), 201);
    }

    #[Endpoint(title: 'Close school communication', description: 'Closes an authorized communication thread while retaining its immutable messages.')] #[Response(status: 200, description: 'The closed thread.', type: 'array')]
    public function close(Request $request, string $school, string $thread, ParentCommunicationAction $action): JsonResponse
    {
        return ApiResponse::data($action->close($this->identity($request), $school, $thread));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
