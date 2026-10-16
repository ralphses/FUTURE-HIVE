<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Application\Actions;

use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Registry\Domain\Models\CounsellingCase;
use App\Contexts\Registry\Domain\Models\CounsellingCaseNote;
use App\Contexts\Registry\Domain\Models\CounsellorAssignment;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

final class CounsellingCaseAction
{
    public function __construct(private readonly SchoolPermissionChecker $permissions, private readonly RecordAuditEventAction $audit) {}

    /** @return array{items: list<array<string,mixed>>} */
    public function index(UserIdentity $actor, string $school): array
    {
        $this->authorize($actor, $school, 'counselling.cases.read');
        $query = CounsellingCase::query()->where('school_id', TenantContext::require()->schoolId)->with(['assignment.staffProfile', 'student'])->withCount('notes');
        if (! $this->isLeadership($actor)) {
            $query->whereHas('assignment', fn ($q) => $q->where('status', 'active')->whereHas('membership', fn ($m) => $m->where('user_id', $actor->getKey())));
        }

        return ['items' => $query->orderByDesc('opened_at')->orderByDesc('id')->get()->map(fn (CounsellingCase $case): array => $this->caseData($case))->all()];
    }

    /** @return array<string,mixed> */
    public function show(UserIdentity $actor, string $school, string $caseId): array
    {
        $this->authorize($actor, $school, 'counselling.cases.read');

        return $this->caseData($this->accessibleCase($actor, $caseId));
    }

    /**
     * @param  array{assignment_id:string,title:string,category:string,reason?:string|null}  $input
     * @return array<string,mixed>
     */
    public function create(UserIdentity $actor, string $school, array $input): array
    {
        $this->authorize($actor, $school, 'counselling.cases.manage');

        return DB::transaction(function () use ($actor, $input): array {
            $context = TenantContext::require();
            $assignment = CounsellorAssignment::query()->where('school_id', $context->schoolId)->where('public_id', $input['assignment_id'])->with(['staffProfile.membership', 'student', 'enrollment'])->lockForUpdate()->first();
            if (! $assignment instanceof CounsellorAssignment || ! $this->activeAssignment($assignment) || (! $this->isLeadership($actor) && ! $this->assignedTo($actor, $assignment))) {
                throw new ModelNotFoundException;
            }
            $existing = CounsellingCase::query()->where('active_case_key', $context->schoolId.':'.$assignment->getKey())->first();
            if ($existing instanceof CounsellingCase) {
                return $this->caseData($existing->load(['assignment.staffProfile', 'student'])->loadCount('notes'));
            }
            $case = CounsellingCase::query()->create(['school_id' => $context->schoolId, 'counsellor_assignment_id' => $assignment->getKey(), 'staff_profile_id' => $assignment->staff_profile_id, 'student_id' => $assignment->student_id, 'student_enrollment_id' => $assignment->student_enrollment_id, 'title' => trim($input['title']), 'category' => trim($input['category']), 'status' => 'open', 'opened_by' => (int) $actor->getAuthIdentifier(), 'opened_at' => now(), 'transition_reason' => $this->nullable($input['reason'] ?? null), 'active_case_key' => $context->schoolId.':'.$assignment->getKey(), 'request_id' => Context::get('request_id')])->load(['assignment.staffProfile', 'student'])->loadCount('notes');
            $this->audit($actor, 'counselling.case_created', (string) $case->public_id, null, 'open');

            return $this->caseData($case);
        });
    }

    /** @return array<string,mixed> */
    public function transition(UserIdentity $actor, string $school, string $caseId, string $target, string $reason): array
    {
        $this->authorize($actor, $school, 'counselling.cases.manage');

        return DB::transaction(function () use ($actor, $caseId, $target, $reason): array {
            $case = $this->accessibleCase($actor, $caseId, true);
            if ($case->status === $target) {
                return $this->caseData($case);
            }
            if (($target === 'on_hold' && $case->status !== 'open') || ($target === 'closed' && ! in_array($case->status, ['open', 'on_hold'], true))) {
                throw new ModelNotFoundException;
            }
            $case->fill(['status' => $target, 'transition_reason' => trim($reason), 'active_case_key' => $target === 'closed' ? null : $case->active_case_key]);
            $case->setAttribute($target === 'on_hold' ? 'hold_at' : 'closed_at', now());
            $case->save();
            $this->audit($actor, 'counselling.case_'.$target, (string) $case->public_id, $case->getOriginal('status'), $target);

            return $this->caseData($case->load(['assignment.staffProfile', 'student'])->loadCount('notes'));
        });
    }

    /** @return array{items:list<array<string,mixed>>} */
    public function notes(UserIdentity $actor, string $school, string $caseId): array
    {
        $case = $this->noteCase($actor, $school, $caseId);

        return ['items' => $case->notes()->orderBy('created_at')->orderBy('id')->get()->map(fn (CounsellingCaseNote $note): array => $this->noteData($note))->all()];
    }

    /**
     * @param  array{note_type:string,content:string}  $input
     * @return array<string,mixed>
     */
    public function createNote(UserIdentity $actor, string $school, string $caseId, array $input): array
    {
        $case = $this->noteCase($actor, $school, $caseId, true);
        if (in_array($case->status, ['closed', 'archived'], true)) {
            throw new ModelNotFoundException;
        }
        $note = $case->notes()->create(['school_id' => TenantContext::require()->schoolId, 'author_id' => (int) $actor->getAuthIdentifier(), 'note_type' => $input['note_type'], 'content' => trim($input['content']), 'request_id' => Context::get('request_id')]);
        $this->audit($actor, 'counselling.note_created', (string) $note->public_id, null, 'created', ['note_type' => $note->note_type]);

        return $this->noteData($note);
    }

    /** @return array<string,mixed> */
    public function showNote(UserIdentity $actor, string $school, string $caseId, string $noteId): array
    {
        $case = $this->noteCase($actor, $school, $caseId);
        $note = $case->notes()->where('public_id', $noteId)->first();
        if (! $note instanceof CounsellingCaseNote) {
            throw new ModelNotFoundException;
        }

        return $this->noteData($note);
    }

    private function noteCase(UserIdentity $actor, string $school, string $caseId, bool $forWrite = false): CounsellingCase
    {
        $this->authorize($actor, $school, $forWrite ? 'counselling.notes.manage' : 'counselling.notes.read');
        $case = $this->accessibleCase($actor, $caseId);
        if (! $this->assignedTo($actor, $case->assignment)) {
            throw new ModelNotFoundException;
        }

        return $case;
    }

    private function accessibleCase(UserIdentity $actor, string $caseId, bool $lock = false): CounsellingCase
    {
        $query = CounsellingCase::query()->where('school_id', TenantContext::require()->schoolId)->where('public_id', $caseId)->with(['assignment.staffProfile.membership', 'student'])->withCount('notes');
        if (! $this->isLeadership($actor)) {
            $query->whereHas('assignment', fn ($q) => $q->where('status', 'active')->whereHas('membership', fn ($m) => $m->where('user_id', $actor->getKey())));
        }
        if ($lock) {
            $query->lockForUpdate();
        }
        $case = $query->first();
        if (! $case instanceof CounsellingCase) {
            throw new ModelNotFoundException;
        }
        if (! $this->isLeadership($actor) && ! $this->activeAssignment($case->assignment)) {
            throw new ModelNotFoundException;
        }

        return $case;
    }

    private function authorize(UserIdentity $actor, string $school, string $permission): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
    }

    private function isLeadership(UserIdentity $actor): bool
    {
        return SchoolMembership::query()->where('school_id', TenantContext::require()->schoolId)->where('user_id', $actor->getKey())->where('status', 'active')->whereHas('roleAssignments', fn ($q) => $q->whereNull('revoked_at')->whereHas('role', fn ($r) => $r->whereIn('key', ['school_admin', 'principal'])))->exists();
    }

    private function assignedTo(UserIdentity $actor, CounsellorAssignment $assignment): bool
    {
        return $assignment->membership?->user_id === $actor->getKey();
    }

    private function activeAssignment(CounsellorAssignment $assignment): bool
    {
        $assignment->loadMissing(['staffProfile.membership.roleAssignments.role', 'student', 'enrollment']);

        return $assignment->status === 'active' && $assignment->staffProfile?->employment_status === 'active' && $assignment->staffProfile->membership?->status === 'active' && $assignment->staffProfile->membership->roleAssignments->contains(fn ($role): bool => $role->revoked_at === null && $role->role?->key === 'counsellor') && $assignment->student?->status === 'active' && $assignment->enrollment?->status === 'active';
    }

    /** @return array<string,mixed> */
    private function caseData(CounsellingCase $case): array
    {
        return ['id' => (string) $case->public_id, 'assignment_id' => (string) $case->assignment?->public_id, 'staff_id' => (string) $case->staffProfile?->public_id, 'staff_name' => (string) ($case->staffProfile?->preferred_name ?: $case->staffProfile?->legal_name), 'student_id' => (string) $case->student?->public_id, 'student_number' => (string) $case->student?->student_number, 'student_name' => (string) $case->student?->display_name, 'title' => (string) $case->title, 'category' => (string) $case->category, 'status' => (string) $case->status, 'note_count' => (int) ($case->notes_count ?? 0), 'opened_at' => $this->timestamp($case->opened_at), 'hold_at' => $this->timestamp($case->hold_at), 'closed_at' => $this->timestamp($case->closed_at)];
    }

    /** @return array<string,mixed> */
    private function noteData(CounsellingCaseNote $note): array
    {
        return ['id' => (string) $note->public_id, 'case_id' => (string) $note->case?->public_id, 'note_type' => (string) $note->note_type, 'content' => (string) $note->content, 'created_at' => CarbonImmutable::parse((string) $note->created_at)->toISOString()];
    }

    /** @param array<string, scalar|null> $extra */
    private function audit(UserIdentity $actor, string $action, string $subject, ?string $from, string $to, array $extra = []): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(action: $action, subjectType: str_starts_with($action, 'counselling.note') ? 'counselling_case_note' : 'counselling_case', subjectPublicId: $subject, schoolId: $context->schoolId, actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), authorizationContext: ['permission' => str_starts_with($action, 'counselling.note') ? 'counselling.notes.manage' : 'counselling.cases.manage'], stateTransition: ['from' => $from, 'to' => $to] + $extra, requiresSchoolContext: true));
    }

    private function nullable(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }

    private function timestamp(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->toISOString();
    }
}
