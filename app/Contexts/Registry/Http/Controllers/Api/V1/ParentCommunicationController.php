<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Controllers\Api\V1;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Application\Actions\ParentCommunicationAction;
use App\Contexts\Registry\Http\Requests\ParentCommunicationMessageRequest;
use App\Contexts\Registry\Http\Requests\ParentCommunicationThreadRequest;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ParentCommunicationController
{
    #[Endpoint(title: 'List parent communications', description: 'Lists safe published announcements and communication threads for the authenticated guardian’s active verified student relationships.')] #[Response(status: 200, description: 'Authorized parent communications.', type: 'array')]
    public function index(Request $request, ParentCommunicationAction $action): JsonResponse
    {
        return ApiResponse::data($action->parentIndex($this->identity($request)));
    }

    #[Endpoint(title: 'Open a parent communication thread', description: 'Opens a bounded in-app support thread for one active verified linked student. School and recipient ownership are resolved by the server.')] #[Response(status: 201, description: 'The communication thread.', type: 'array')]
    public function store(ParentCommunicationThreadRequest $request, ParentCommunicationAction $action): JsonResponse
    {
        return ApiResponse::data($action->createThread($this->identity($request), $request->validated()), 201);
    }

    #[Endpoint(title: 'View a parent communication thread', description: 'Returns one communication thread only when it belongs to an active verified linked student.')] #[Response(status: 200, description: 'The communication thread.', type: 'array')]
    public function show(Request $request, string $thread, ParentCommunicationAction $action): JsonResponse
    {
        return ApiResponse::data($action->parentThread($this->identity($request), $thread));
    }

    #[Endpoint(title: 'Send a parent communication message', description: 'Adds an immutable bounded message to an open authorized thread. No external delivery is performed.')] #[Response(status: 201, description: 'The message.', type: 'array')]
    public function message(ParentCommunicationMessageRequest $request, string $thread, ParentCommunicationAction $action): JsonResponse
    {
        return ApiResponse::data($action->parentMessage($this->identity($request), $thread, (string) $request->validated('body')), 201);
    }

    #[Endpoint(title: 'Mark parent communication read', description: 'Records in-app read state for an authorized communication thread.')] #[Response(status: 200, description: 'Read state.', type: 'array')]
    public function read(Request $request, string $thread, ParentCommunicationAction $action): JsonResponse
    {
        return ApiResponse::data($action->markParentRead($this->identity($request), $thread));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
