<?php

declare(strict_types=1);

namespace App\Support;

use App\Contexts\Academic\Domain\Models\AcademicResultGate;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Domain\Models\SchoolSetupChecklistItem;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;

class ProprietorExecutiveDashboardAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly PrincipalReviewDashboardAction $principalDashboard,
    ) {}

    /**
     * @param  array{session?: string|null, term?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function execute(UserIdentity $actor, string $school, array $filters = []): array
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, 'operations.proprietor-dashboard.read')) {
            throw new ModelNotFoundException;
        }

        $review = $this->principalDashboard->execute($actor, $school, $filters);
        $setup = SchoolSetupChecklistItem::query()->orderBy('item_key')->get();
        $completed = $setup->where('status', 'completed')->count();
        $total = $setup->count();
        $latestSetup = $setup->sortByDesc('updated_at')->first();
        $termId = $review['term_id'];
        $gates = $termId === null ? collect() : AcademicResultGate::query()
            ->where('academic_term_id', function ($query) use ($context, $termId): void {
                $query->select('id')->from('academic_terms')->where('school_id', $context->schoolId)->where('public_id', $termId);
            })
            ->get();
        $publicationStatuses = $gates->groupBy('status')->map->count()->sortKeys()->all();

        return [
            'id' => 'proprietor-executive-dashboard',
            'request_id' => Context::get('request_id'),
            'school_id' => $school,
            'session_id' => $review['session_id'],
            'term_id' => $termId,
            'sections' => [
                'review' => $review['sections'],
                'academic_publication' => [
                    'counts_by_status' => $publicationStatuses,
                    'issue_codes' => $gates->where('status', '!=', 'published')->isNotEmpty() ? ['RESULT_PUBLICATION_PENDING'] : [],
                    'detail_references' => $termId === null ? [] : [['rel' => 'result_review_queue', 'path' => "/api/v1/schools/{$school}/academic-sessions/{$review['session_id']}/terms/{$termId}/result-review-queue"]],
                    'last_updated_at' => $gates->sortByDesc('updated_at')->first()?->updated_at?->toISOString(),
                ],
                'school_setup' => [
                    'completed_count' => $completed,
                    'total_count' => $total,
                    'completion_percentage' => $total === 0 ? 0 : (int) (($completed / $total) * 100),
                    'pending_count' => max(0, $total - $completed),
                    'issue_codes' => $completed < $total ? ['SCHOOL_SETUP_INCOMPLETE'] : [],
                    'detail_references' => [['rel' => 'school_setup', 'path' => "/api/v1/schools/{$school}/setup"]],
                    'last_updated_at' => $latestSetup?->updated_at?->toISOString(),
                ],
            ],
            'generated_at' => now()->toISOString(),
        ];
    }
}
