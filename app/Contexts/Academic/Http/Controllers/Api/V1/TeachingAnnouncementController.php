<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Controllers\Api\V1;

use App\Contexts\Academic\Application\Actions\TeachingAnnouncementAction;
use App\Contexts\Academic\Http\Requests\AnnouncementReasonRequest;
use App\Contexts\Academic\Http\Requests\StoreTeachingAnnouncementRequest;
use App\Contexts\Academic\Http\Requests\UpdateAnnouncementAudienceRequest;
use App\Contexts\Academic\Http\Requests\UpdateTeachingAnnouncementRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TeachingAnnouncementController
{
    #[Endpoint(title: 'List announcement audiences', description: 'Lists safe, same-school class-arm and subject-offering audiences available to authorized school leadership for a selected session and term. Recipients are never returned.')] #[Response(status: 200, description: 'The supported audiences.', type: 'array')]
    public function audiences(Request $request, string $school, TeachingAnnouncementAction $action): JsonResponse
    {
        return ApiResponse::data($action->audiences($this->identity($request), $school, $request->only(['session_id', 'term_id'])));
    }

    #[Endpoint(title: 'List teacher announcements', description: 'Lists school-scoped announcements visible to the authenticated teacher or authorized school leadership. Recipients are resolved from server-controlled class and subject assignments.')] #[Response(status: 200, description: 'The visible announcements.', type: 'array')]
    public function index(Request $request, string $school, TeachingAnnouncementAction $action): JsonResponse
    {
        return ApiResponse::data($action->index($this->identity($request), $school, $request->only(['term_id'])));
    }

    #[Endpoint(title: 'Create a teacher announcement', description: 'Creates a draft announcement for a selected academic term and assigned class or subject audience. The server controls school ownership, recipients and lifecycle state.')] #[Response(status: 201, description: 'The draft announcement.', type: 'array')]
    public function store(StoreTeachingAnnouncementRequest $request, string $school, TeachingAnnouncementAction $action): JsonResponse
    {
        return ApiResponse::data($action->create($this->identity($request), $school, $request->validated()), 201);
    }

    #[Endpoint(title: 'View a teacher announcement', description: 'Returns one announcement only when it belongs to the trusted school and the authenticated user is an authorized audience member or reviewer.')] #[Response(status: 200, description: 'The announcement.', type: 'array')]
    public function show(Request $request, string $school, string $announcement, TeachingAnnouncementAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $announcement));
    }

    #[Endpoint(title: 'Update a draft announcement', description: 'Updates the title or body of a draft or returned announcement. Submitted, approved and published announcements are not silently edited.')] #[Response(status: 200, description: 'The updated announcement.', type: 'array')]
    public function update(UpdateTeachingAnnouncementRequest $request, string $school, string $announcement, TeachingAnnouncementAction $action): JsonResponse
    {
        return ApiResponse::data($action->update($this->identity($request), $school, $announcement, $request->validated()));
    }

    #[Endpoint(title: 'Update announcement audience', description: 'Reassigns a draft or rejected announcement to one server-resolved class-arm or subject-offering audience. Published and submitted announcements cannot be reassigned.')] #[Response(status: 200, description: 'The updated announcement.', type: 'array')]
    public function updateAudience(UpdateAnnouncementAudienceRequest $request, string $school, string $announcement, TeachingAnnouncementAction $action): JsonResponse
    {
        return ApiResponse::data($action->updateAudience($this->identity($request), $school, $announcement, $request->validated()));
    }

    #[Endpoint(title: 'Submit an announcement for review', description: 'Submits a draft announcement for leadership review. Submission does not send external notifications.')] #[Response(status: 200, description: 'The submitted announcement.', type: 'array')]
    public function submit(Request $request, string $school, string $announcement, TeachingAnnouncementAction $action): JsonResponse
    {
        return ApiResponse::data($action->transition($this->identity($request), $school, $announcement, 'submit'));
    }

    #[Endpoint(title: 'Approve an announcement', description: 'Approves a submitted announcement for publication by an authorized school reviewer.')] #[Response(status: 200, description: 'The approved announcement.', type: 'array')]
    public function approve(Request $request, string $school, string $announcement, TeachingAnnouncementAction $action): JsonResponse
    {
        return ApiResponse::data($action->transition($this->identity($request), $school, $announcement, 'approve'));
    }

    #[Endpoint(title: 'Publish an announcement', description: 'Publishes an approved announcement to its server-resolved class or subject audience. No external delivery is performed.')] #[Response(status: 200, description: 'The published announcement.', type: 'array')]
    public function publish(Request $request, string $school, string $announcement, TeachingAnnouncementAction $action): JsonResponse
    {
        return ApiResponse::data($action->transition($this->identity($request), $school, $announcement, 'publish'));
    }

    #[Endpoint(title: 'Recall an announcement', description: 'Recalls a published announcement and retains the publication history.')] #[Response(status: 200, description: 'The recalled announcement.', type: 'array')]
    public function recall(AnnouncementReasonRequest $request, string $school, string $announcement, TeachingAnnouncementAction $action): JsonResponse
    {
        return ApiResponse::data($action->transition($this->identity($request), $school, $announcement, 'recall', $request->validated('reason')));
    }

    #[Endpoint(title: 'Reject an announcement', description: 'Rejects a submitted announcement with a bounded reason. A rejected announcement may be edited and resubmitted.')] #[Response(status: 200, description: 'The rejected announcement.', type: 'array')]
    public function reject(AnnouncementReasonRequest $request, string $school, string $announcement, TeachingAnnouncementAction $action): JsonResponse
    {
        return ApiResponse::data($action->transition($this->identity($request), $school, $announcement, 'reject', $request->validated('reason')));
    }

    private function identity(Request $request): UserIdentity
    {
        return $request->user();
    }
}
