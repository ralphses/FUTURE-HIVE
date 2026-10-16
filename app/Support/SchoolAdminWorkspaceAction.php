<?php

declare(strict_types=1);

namespace App\Support;

use App\Contexts\Academic\Domain\Models\AcademicSession;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Domain\Models\SchoolSetupChecklistItem;
use App\Contexts\Registry\Domain\Models\StaffProfile;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

class SchoolAdminWorkspaceAction
{
    public function __construct(private readonly SchoolPermissionChecker $permissions) {}

    /** @return array<string, mixed> */
    public function execute(UserIdentity $actor, string $school): array
    {
        $context = TenantContext::require();
        $membership = SchoolMembership::query()
            ->whereKey($context->membershipId)
            ->where('user_id', $actor->getKey())
            ->where('status', 'active')
            ->whereHas('roleAssignments', fn ($query) => $query->whereNull('revoked_at')->whereHas('role', fn ($role) => $role->where('key', 'school_admin')->where('is_active', true)))
            ->first();

        $status = DB::table('schools')->where('id', $context->schoolId)->value('status');
        if ($context->schoolPublicId !== $school || $status !== 'active' || ! $membership instanceof SchoolMembership || ! $this->permissions->allows($actor, $school, 'school.settings.read')) {
            throw new ModelNotFoundException;
        }

        $setup = SchoolSetupChecklistItem::query()->orderBy('item_key')->get();
        $completedSetup = $setup->where('status', 'completed')->count();
        $totalSetup = $setup->count();
        $staffStatuses = StaffProfile::query()->where('school_id', $context->schoolId)->select('employment_status', DB::raw('count(*) as aggregate'))->groupBy('employment_status')->orderBy('employment_status')->pluck('aggregate', 'employment_status')->map(static fn ($count): int => (int) $count)->all();
        $activeMemberships = SchoolMembership::query()->where('school_id', $context->schoolId)->where('status', 'active')->count();
        $roleCounts = DB::table('membership_roles')
            ->join('school_memberships', 'school_memberships.id', '=', 'membership_roles.school_membership_id')
            ->join('roles', 'roles.id', '=', 'membership_roles.role_id')
            ->where('school_memberships.school_id', $context->schoolId)
            ->where('school_memberships.status', 'active')
            ->whereNull('membership_roles.revoked_at')
            ->select('roles.key', DB::raw('count(*) as aggregate'))
            ->groupBy('roles.key')
            ->orderBy('roles.key')
            ->pluck('aggregate', 'key')
            ->map(static fn ($count): int => (int) $count)
            ->all();
        $activeSession = AcademicSession::query()->where('school_id', $context->schoolId)->where('status', 'active')->orderBy('start_date')->orderBy('id')->first();
        $activeTerms = AcademicTerm::query()->where('school_id', $context->schoolId)->where('status', 'active');
        $activeTerm = $activeSession instanceof AcademicSession ? (clone $activeTerms)->where('academic_session_id', $activeSession->getKey())->orderBy('sequence')->orderBy('id')->first() : null;
        $issueCodes = [];
        if ($totalSetup > $completedSetup) {
            $issueCodes[] = 'SCHOOL_SETUP_INCOMPLETE';
        }
        if ($activeSession === null || $activeTerm === null) {
            $issueCodes[] = 'ACADEMIC_CONTEXT_UNAVAILABLE';
        }
        if (($staffStatuses['active'] ?? 0) === 0) {
            $issueCodes[] = 'STAFF_SETUP_INCOMPLETE';
        }
        sort($issueCodes);

        return [
            'id' => 'school-admin-workspace',
            'request_id' => Context::get('request_id'),
            'school_id' => $school,
            'sections' => [
                'setup' => [
                    'completed_count' => $completedSetup,
                    'total_count' => $totalSetup,
                    'pending_count' => max(0, $totalSetup - $completedSetup),
                    'completion_percentage' => $totalSetup === 0 ? 0 : (int) (($completedSetup / $totalSetup) * 100),
                    'detail_references' => [['rel' => 'school_setup', 'path' => "/api/v1/schools/{$school}/setup"]],
                ],
                'lifecycle' => [
                    'status' => (string) $status,
                    'detail_references' => [['rel' => 'school_lifecycle', 'path' => "/api/v1/schools/{$school}/lifecycle"]],
                ],
                'staff' => [
                    'total_count' => array_sum($staffStatuses),
                    'counts_by_employment_status' => $staffStatuses,
                    'detail_references' => [['rel' => 'staff', 'path' => "/api/v1/schools/{$school}/staff"]],
                ],
                'memberships' => [
                    'active_count' => $activeMemberships,
                    'role_counts' => $roleCounts,
                    'detail_references' => [['rel' => 'memberships', 'path' => "/api/v1/schools/{$school}/memberships"]],
                ],
                'academic_context' => [
                    'active_session_count' => AcademicSession::query()->where('school_id', $context->schoolId)->where('status', 'active')->count(),
                    'active_term_count' => (clone $activeTerms)->count(),
                    'active_session_id' => $activeSession?->public_id,
                    'active_term_id' => $activeTerm?->public_id,
                    'detail_references' => [['rel' => 'academic_context', 'path' => "/api/v1/schools/{$school}/academic-sessions"]],
                ],
            ],
            'issue_codes' => $issueCodes,
            'generated_at' => now()->toISOString(),
        ];
    }
}
