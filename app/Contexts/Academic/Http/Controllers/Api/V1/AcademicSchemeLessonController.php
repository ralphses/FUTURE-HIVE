<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Controllers\Api\V1;

use App\Contexts\Academic\Application\Actions\AcademicSchemeLessonAction;
use App\Contexts\Academic\Http\Requests\ProgressSchemeTopicRequest;
use App\Contexts\Academic\Http\Requests\ReviewAcademicLessonRequest;
use App\Contexts\Academic\Http\Requests\StoreAcademicLessonRequest;
use App\Contexts\Academic\Http\Requests\StoreSchemeTopicRequest;
use App\Contexts\Academic\Http\Requests\UpdateAcademicLessonRequest;
use App\Contexts\Academic\Http\Requests\UpdateSchemeTopicRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AcademicSchemeLessonController
{
    #[Endpoint(title: 'View a scheme of work', description: 'Shows the selected subject offering scheme and its ordered teaching topics. Scheme ownership and academic parents come from the trusted school context.')]
    #[Response(status: 200, description: 'The scheme of work and its topics.', type: 'array')]
    public function showScheme(Request $request, string $school, string $session, string $term, string $offering, AcademicSchemeLessonAction $action): JsonResponse
    {
        return ApiResponse::data($action->showScheme($this->identity($request), $school, $session, $term, $offering));
    }

    #[Endpoint(title: 'Create a scheme of work', description: 'Creates the school-owned scheme container for an active subject offering. It does not create a global curriculum or mark any topic complete.')]
    #[Response(status: 201, description: 'The created scheme.', type: 'array')]
    public function storeScheme(Request $request, string $school, string $session, string $term, string $offering, AcademicSchemeLessonAction $action): JsonResponse
    {
        return ApiResponse::data($action->createScheme($this->identity($request), $school, $session, $term, $offering), 201);
    }

    #[Endpoint(title: 'List scheme topics', description: 'Lists ordered topics for the selected school subject offering. Topic progress is server-controlled and does not infer lesson delivery.')]
    #[Response(status: 200, description: 'The scheme topics.', type: 'array')]
    public function topics(Request $request, string $school, string $session, string $term, string $offering, AcademicSchemeLessonAction $action): JsonResponse
    {
        return ApiResponse::data($action->topics($this->identity($request), $school, $session, $term, $offering));
    }

    #[Endpoint(title: 'Add a scheme topic', description: 'Adds one ordered school-defined topic to an existing scheme. The server controls school ownership and lifecycle state.')]
    #[Response(status: 201, description: 'The created topic.', type: 'array')]
    public function storeTopic(StoreSchemeTopicRequest $request, string $school, string $session, string $term, string $offering, AcademicSchemeLessonAction $action): JsonResponse
    {
        return ApiResponse::data($action->createTopic($this->identity($request), $school, $session, $term, $offering, $request->validated()), 201);
    }

    #[Endpoint(title: 'Update a scheme topic', description: 'Updates a planned or in-progress topic. Completed topics are retained and cannot be rewritten in this slice.')]
    #[Response(status: 200, description: 'The updated topic.', type: 'array')]
    public function updateTopic(UpdateSchemeTopicRequest $request, string $school, string $session, string $term, string $offering, string $topic, AcademicSchemeLessonAction $action): JsonResponse
    {
        return ApiResponse::data($action->updateTopic($this->identity($request), $school, $session, $term, $offering, $topic, $request->validated()));
    }

    #[Endpoint(title: 'Update scheme topic progress', description: 'Sets a topic to planned, in progress or completed. This does not create attendance or prove that a lesson was taught.')]
    #[Response(status: 200, description: 'The updated topic progress.', type: 'array')]
    public function progressTopic(ProgressSchemeTopicRequest $request, string $school, string $session, string $term, string $offering, string $topic, AcademicSchemeLessonAction $action): JsonResponse
    {
        return ApiResponse::data($action->progressTopic($this->identity($request), $school, $session, $term, $offering, $topic, $request->string('status')->toString()));
    }

    #[Endpoint(title: 'List lesson records', description: 'Lists lessons for the selected subject offering. Teachers see their own assigned lessons; authorized reviewers see the school-scoped review queue.')]
    #[Response(status: 200, description: 'The lesson records.', type: 'array')]
    public function lessons(Request $request, string $school, string $session, string $term, string $offering, AcademicSchemeLessonAction $action): JsonResponse
    {
        return ApiResponse::data($action->lessons($this->identity($request), $school, $session, $term, $offering));
    }

    #[Endpoint(title: 'Create a lesson record', description: 'Creates a draft lesson for the authenticated teacher’s active subject assignment. It does not call an AI provider or create attendance or assessment data.')]
    #[Response(status: 201, description: 'The draft lesson.', type: 'array')]
    public function storeLesson(StoreAcademicLessonRequest $request, string $school, string $session, string $term, string $offering, AcademicSchemeLessonAction $action): JsonResponse
    {
        return ApiResponse::data($action->createLesson($this->identity($request), $school, $session, $term, $offering, $request->validated()), 201);
    }

    #[Endpoint(title: 'View a lesson record', description: 'Returns one school-scoped lesson record and its current review state without exposing internal identifiers or private identity data.')]
    #[Response(status: 200, description: 'The lesson record.', type: 'array')]
    public function showLesson(Request $request, string $school, string $session, string $term, string $offering, string $lesson, AcademicSchemeLessonAction $action): JsonResponse
    {
        return ApiResponse::data($action->showLesson($this->identity($request), $school, $session, $term, $offering, $lesson));
    }

    #[Endpoint(title: 'Update a draft lesson', description: 'Updates a draft or returned lesson owned by the authenticated teacher. Submitted and approved lessons cannot be silently edited.')]
    #[Response(status: 200, description: 'The updated lesson.', type: 'array')]
    public function updateLesson(UpdateAcademicLessonRequest $request, string $school, string $session, string $term, string $offering, string $lesson, AcademicSchemeLessonAction $action): JsonResponse
    {
        return ApiResponse::data($action->updateLesson($this->identity($request), $school, $session, $term, $offering, $lesson, $request->validated()));
    }

    #[Endpoint(title: 'Submit a lesson for review', description: 'Submits a teacher lesson and retains a safe submitted snapshot. The lesson becomes unavailable for ordinary teacher editing until it is returned.')]
    #[Response(status: 200, description: 'The submitted lesson.', type: 'array')]
    public function submitLesson(Request $request, string $school, string $session, string $term, string $offering, string $lesson, AcademicSchemeLessonAction $action): JsonResponse
    {
        return ApiResponse::data($action->submitLesson($this->identity($request), $school, $session, $term, $offering, $lesson));
    }

    #[Endpoint(title: 'Approve a lesson', description: 'Approves a submitted lesson for the selected school. Approval means the preparation was reviewed; it does not mean the lesson was taught.')]
    #[Response(status: 200, description: 'The approved lesson.', type: 'array')]
    public function approveLesson(ReviewAcademicLessonRequest $request, string $school, string $session, string $term, string $offering, string $lesson, AcademicSchemeLessonAction $action): JsonResponse
    {
        return ApiResponse::data($action->reviewLesson($this->identity($request), $school, $session, $term, $offering, $lesson, 'approved', $request->string('reason')->toString()));
    }

    #[Endpoint(title: 'Return a lesson for correction', description: 'Returns a submitted lesson with a bounded reason. The teacher may edit and resubmit it; the prior submitted snapshot remains retained.')]
    #[Response(status: 200, description: 'The returned lesson.', type: 'array')]
    public function returnLesson(ReviewAcademicLessonRequest $request, string $school, string $session, string $term, string $offering, string $lesson, AcademicSchemeLessonAction $action): JsonResponse
    {
        return ApiResponse::data($action->reviewLesson($this->identity($request), $school, $session, $term, $offering, $lesson, 'changes_requested', $request->string('reason')->toString()));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
