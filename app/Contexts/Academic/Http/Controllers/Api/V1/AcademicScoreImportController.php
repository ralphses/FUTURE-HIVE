<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Controllers\Api\V1;

use App\Contexts\Academic\Application\Actions\AcademicAssessmentScoreAction;
use App\Contexts\Academic\Http\Requests\UploadAcademicScoreImportRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\ImportBatchAction;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

final class AcademicScoreImportController
{
    public function store(UploadAcademicScoreImportRequest $request, string $school, string $session, string $term, string $offering, string $instance, AcademicAssessmentScoreAction $scores, ImportBatchAction $imports): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof UserIdentity, 401);
        $assessmentInstance = $scores->authorizeImport($actor, $school, $session, $term, $offering, $instance);

        return ApiResponse::data($imports->upload($actor, $school, 'assessment_scores', 'v1', $request->file('file'), $assessmentInstance->getKey(), 'academic.scores.manage'), 201);
    }
}
