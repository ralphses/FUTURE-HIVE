<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicResultEntry;
use App\Contexts\Academic\Domain\Models\AcademicResultRun;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;

final class AcademicResultReviewQueueAction
{
    public function __construct(private readonly SchoolPermissionChecker $permissions) {}

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function index(UserIdentity $actor, string $school, string $session, string $term): LengthAwarePaginator
    {
        $academicTerm = $this->authorizeTerm($actor, $school, $session, $term);

        return AcademicResultRun::query()
            ->where('academic_term_id', $academicTerm->getKey())
            ->whereIn('status', ['completed', 'blocked'])
            ->with(['entries:id,academic_result_run_id,status,error_code'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(ApiResponse::perPage(request()->integer('per_page')))
            ->through(fn (AcademicResultRun $run): array => $this->runData($run, $academicTerm));
    }

    /** @return array{run: array<string, mixed>, entries: list<array<string, mixed>>} */
    public function show(UserIdentity $actor, string $school, string $session, string $term, string $runId): array
    {
        $academicTerm = $this->authorizeTerm($actor, $school, $session, $term);
        $run = AcademicResultRun::query()
            ->where('academic_term_id', $academicTerm->getKey())
            ->whereIn('status', ['completed', 'blocked'])
            ->where('public_id', $runId)
            ->with(['entries' => function ($query): void {
                $query->select(['id', 'public_id', 'academic_result_run_id', 'academic_subject_offering_id', 'student_id', 'status', 'error_code'])
                    ->with(['student:id,public_id,display_name', 'offering:id,public_id']);
            }])
            ->firstOrFail();

        return [
            'run' => $this->runData($run, $academicTerm),
            'entries' => $run->entries->map(fn (AcademicResultEntry $entry): array => $this->entryData($entry))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function runData(AcademicResultRun $run, AcademicTerm $academicTerm): array
    {
        $entries = $run->relationLoaded('entries') ? $run->entries : collect();
        $issueCodes = $entries->pluck('error_code')->filter()->unique()->sort()->values()->all();
        $reviewable = $run->status === 'completed' && (int) $run->not_computable_count === 0;

        return [
            'id' => (string) $run->public_id,
            'session_id' => (string) $academicTerm->session?->public_id,
            'term_id' => (string) $academicTerm->public_id,
            'status' => (string) $run->status,
            'completeness_status' => $reviewable ? 'ready' : 'blocked',
            'review_status' => $reviewable ? 'ready_for_review' : 'blocked',
            'computed_count' => (int) $run->computed_count,
            'not_computable_count' => (int) $run->not_computable_count,
            'issue_codes' => $issueCodes,
            'created_at' => $run->created_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function entryData(AcademicResultEntry $entry): array
    {
        return [
            'id' => (string) $entry->public_id,
            'student_id' => (string) $entry->student?->public_id,
            'student_name' => (string) $entry->student?->display_name,
            'offering_id' => (string) $entry->offering?->public_id,
            'status' => (string) $entry->status,
            'error_code' => $entry->error_code,
        ];
    }

    private function authorizeTerm(UserIdentity $actor, string $school, string $session, string $term): AcademicTerm
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, 'academic.results.review.read')) {
            throw new ModelNotFoundException;
        }

        $academicTerm = AcademicTerm::query()
            ->where('school_id', $context->schoolId)
            ->where('public_id', $term)
            ->whereHas('session', fn ($query) => $query->where('school_id', $context->schoolId)->where('public_id', $session))
            ->with('session')
            ->first();

        if (! $academicTerm instanceof AcademicTerm) {
            throw new ModelNotFoundException;
        }

        return $academicTerm;
    }
}
