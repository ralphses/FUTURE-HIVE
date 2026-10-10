<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Controllers\Api\V1;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Application\Actions\StudentDocumentAction;
use App\Contexts\Registry\Application\Actions\StudentProfileAction;
use App\Contexts\Registry\Http\Requests\UpdateStudentProfileRequest;
use App\Contexts\Registry\Http\Requests\UploadStudentDocumentRequest;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StudentProfileController
{
    #[Endpoint(title: 'View a student profile', description: 'Returns the approved profile fields for one student in the selected school. It does not include guardian, enrolment or attendance data.')]
    public function showProfile(Request $request, string $school, string $student, StudentProfileAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $student));
    }

    #[Endpoint(title: 'Update a student profile', description: 'Creates or replaces the bounded student profile for an admitted student. Admission status and school ownership are unchanged.')]
    public function updateProfile(UpdateStudentProfileRequest $request, string $school, string $student, StudentProfileAction $action): JsonResponse
    {
        return ApiResponse::data($action->update($this->identity($request), $school, $student, $request->validated()));
    }

    #[Endpoint(title: 'List student documents', description: 'Lists active private documents attached to a student. Returned download links are short-lived and school-authorized.')]
    public function documents(Request $request, string $school, string $student, StudentDocumentAction $action): JsonResponse
    {
        return ApiResponse::data($action->index($this->identity($request), $school, $student));
    }

    #[Endpoint(title: 'Upload a student document', description: 'Scans and stores a controlled student document as a private authenticated Cloudinary asset.')]
    public function uploadDocument(UploadStudentDocumentRequest $request, string $school, string $student, StudentDocumentAction $action): JsonResponse
    {
        return ApiResponse::data($action->upload($this->identity($request), $school, $student, (string) $request->validated('category'), $request->file('document')), 201);
    }

    #[Endpoint(title: 'Get a student document download link', description: 'Returns a short-lived application-signed download link for an active private student document. The provider URL is never exposed.')]
    public function downloadDocument(Request $request, string $school, string $student, string $document, StudentDocumentAction $action): JsonResponse
    {
        return ApiResponse::data($action->download($this->identity($request), $school, $student, $document));
    }

    #[Endpoint(title: 'Revoke a student document', description: 'Revokes a private student document while retaining its audit and historical metadata. It does not delete the provider asset.')]
    public function revokeDocument(Request $request, string $school, string $student, string $document, StudentDocumentAction $action): JsonResponse
    {
        return ApiResponse::data($action->revoke($this->identity($request), $school, $student, $document));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
