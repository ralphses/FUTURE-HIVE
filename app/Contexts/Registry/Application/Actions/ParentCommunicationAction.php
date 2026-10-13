<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Application\Actions;

use App\Contexts\Academic\Domain\Models\TeachingAnnouncement;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Registry\Domain\Models\ParentCommunicationMessage;
use App\Contexts\Registry\Domain\Models\ParentCommunicationThread;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ParentCommunicationAction
{
    public function __construct(private readonly RecordAuditEventAction $audit, private readonly SchoolPermissionChecker $permissions) {}

    /** @return array{announcements: list<array<string, mixed>>, threads: list<array<string, mixed>>} */
    public function parentIndex(UserIdentity $actor): array
    {
        $links = $this->guardianLinks($actor);
        $threads = ParentCommunicationThread::query()->whereIn('guardian_profile_id', $links->pluck('guardian_profile_id')->all())->withCount('messages')->orderByDesc('id')->limit(50)->get()->filter(fn (ParentCommunicationThread $thread): bool => $links->contains(fn (object $link): bool => (int) $link->school_id === (int) $thread->school_id && (int) $link->student_id === (int) $thread->student_id))->map(fn (ParentCommunicationThread $thread): array => $this->threadData($thread))->values()->all();
        $announcements = $this->announcements($links);
        $this->auditRead($actor, count($threads) + count($announcements), 'parent');

        return ['announcements' => $announcements, 'threads' => $threads];
    }

    /** @return array{thread: array<string, mixed>} */
    public function parentThread(UserIdentity $actor, string $threadId): array
    {
        $links = $this->guardianLinks($actor);
        $thread = ParentCommunicationThread::query()->where('public_id', $threadId)->whereIn('guardian_profile_id', $links->pluck('guardian_profile_id')->all())->with('messages.author')->first();
        if (! $thread instanceof ParentCommunicationThread || ! $links->contains(fn (object $link): bool => (int) $link->school_id === (int) $thread->school_id && (int) $link->student_id === (int) $thread->student_id && (int) $link->guardian_profile_id === (int) $thread->guardian_profile_id)) {
            throw new ModelNotFoundException;
        }
        $this->auditRead($actor, 1, 'parent');

        return ['thread' => $this->threadData($thread, true)];
    }

    /**
     * @param  array{student: string, title: string, body: string}  $input
     * @return array{thread: array<string, mixed>}
     */
    public function createThread(UserIdentity $actor, array $input): array
    {
        $link = $this->guardianLinks($actor, (string) $input['student'])->first();
        if ($link === null) {
            throw new ModelNotFoundException;
        }
        $thread = DB::transaction(function () use ($actor, $input, $link): ParentCommunicationThread {
            $thread = ParentCommunicationThread::query()->create(['school_id' => $link->school_id, 'student_id' => $link->student_id, 'guardian_profile_id' => $link->guardian_profile_id, 'title' => trim((string) $input['title']), 'status' => 'open', 'created_by' => $actor->getKey()]);
            $this->createMessage($thread, $actor, (string) $input['body']);

            return $thread->load('messages.author');
        });
        $this->auditMutation($actor, 'parent.communication_thread_created', (string) $thread->public_id, 'communications.parent.manage');

        return ['thread' => $this->threadData($thread, true)];
    }

    /** @return array{message: array<string, mixed>} */
    public function parentMessage(UserIdentity $actor, string $threadId, string $body): array
    {
        $thread = $this->parentThreadRecord($actor, $threadId);
        $message = $this->message($thread, $actor, $body);

        return ['message' => $this->messageData($message->load('author'))];
    }

    /** @return array{read: bool} */
    public function markParentRead(UserIdentity $actor, string $threadId): array
    {
        $thread = $this->parentThreadRecord($actor, $threadId);
        DB::table('parent_communication_reads')->updateOrInsert(['thread_id' => $thread->getKey(), 'user_id' => $actor->getKey()], ['read_at' => now(), 'updated_at' => now(), 'created_at' => now()]);
        $this->auditMutation($actor, 'parent.communication_read', (string) $thread->public_id, 'communications.parent.read');

        return ['read' => true];
    }

    /** @return array{threads: list<array<string, mixed>>} */
    public function staffIndex(UserIdentity $actor, string $school): array
    {
        $context = $this->staffContext($actor, $school, 'communications.staff.read');
        $threads = ParentCommunicationThread::query()->where('school_id', $context->schoolId)->where(function ($query) use ($actor, $context): void {
            if ($this->isLeadership($actor, $context->membershipId)) {
                return;
            }
            $query->whereIn('student_id', $this->staffStudentIds($actor, $context));
        })->with(['student', 'messages.author'])->orderByDesc('id')->limit(100)->get()->map(fn (ParentCommunicationThread $thread): array => $this->threadData($thread))->all();
        $this->auditRead($actor, count($threads), 'staff');

        return ['threads' => $threads];
    }

    /** @return array{thread: array<string, mixed>} */
    public function staffThread(UserIdentity $actor, string $school, string $threadId): array
    {
        $context = $this->staffContext($actor, $school, 'communications.staff.read');
        $thread = ParentCommunicationThread::query()->where('school_id', $context->schoolId)->where('public_id', $threadId)->with('messages.author')->first();
        if (! $thread instanceof ParentCommunicationThread || ! $this->staffCanAccess($actor, $context, (int) $thread->student_id)) {
            throw new ModelNotFoundException;
        }
        $this->auditRead($actor, 1, 'staff');

        return ['thread' => $this->threadData($thread, true)];
    }

    /** @return array{message: array<string, mixed>} */
    public function staffMessage(UserIdentity $actor, string $school, string $threadId, string $body): array
    {
        $thread = $this->staffThreadRecord($actor, $school, $threadId, 'communications.staff.manage');

        return ['message' => $this->messageData($this->message($thread, $actor, $body)->load('author'))];
    }

    /** @return array{thread: array<string, mixed>} */
    public function close(UserIdentity $actor, string $school, string $threadId): array
    {
        $context = $this->staffContext($actor, $school, 'communications.staff.manage');
        if (! $this->isLeadership($actor, $context->membershipId)) {
            throw new ModelNotFoundException;
        }
        $thread = ParentCommunicationThread::query()->where('school_id', $context->schoolId)->where('public_id', $threadId)->first();
        if (! $thread instanceof ParentCommunicationThread) {
            throw new ModelNotFoundException;
        }
        if ($thread->status !== 'closed') {
            $thread->fill(['status' => 'closed', 'closed_by' => $actor->getKey(), 'closed_at' => now()])->save();
            $this->auditMutation($actor, 'parent.communication_thread_closed', (string) $thread->public_id, 'communications.staff.manage');
        }

        return ['thread' => $this->threadData($thread->load('messages.author'))];
    }

    private function parentThreadRecord(UserIdentity $actor, string $threadId): ParentCommunicationThread
    {
        $links = $this->guardianLinks($actor);
        $thread = ParentCommunicationThread::query()->where('public_id', $threadId)->whereIn('guardian_profile_id', $links->pluck('guardian_profile_id')->all())->first();
        if (! $thread instanceof ParentCommunicationThread || ! $links->contains(fn (object $link): bool => (int) $link->school_id === (int) $thread->school_id && (int) $link->student_id === (int) $thread->student_id)) {
            throw new ModelNotFoundException;
        }

        return $thread;
    }

    private function staffThreadRecord(UserIdentity $actor, string $school, string $threadId, string $permission): ParentCommunicationThread
    {
        $context = $this->staffContext($actor, $school, $permission);
        $thread = ParentCommunicationThread::query()->where('school_id', $context->schoolId)->where('public_id', $threadId)->first();
        if (! $thread instanceof ParentCommunicationThread || ! $this->staffCanAccess($actor, $context, (int) $thread->student_id)) {
            throw new ModelNotFoundException;
        }

        return $thread;
    }

    private function message(ParentCommunicationThread $thread, UserIdentity $actor, string $body): ParentCommunicationMessage
    {
        if ($thread->status !== 'open') {
            throw ValidationException::withMessages(['thread' => ['This communication thread is not open.']]);
        }
        $key = trim((string) request()->header('Idempotency-Key'));
        $hash = $key === '' ? null : hash_hmac('sha256', $key, (string) config('app.key'));
        $fingerprint = hash('sha256', $thread->getKey().'|'.$actor->getKey().'|'.trim($body));
        if ($hash !== null) {
            $existing = ParentCommunicationMessage::query()->where('thread_id', $thread->getKey())->where('author_id', $actor->getKey())->where('idempotency_key_hash', $hash)->first();
            if ($existing instanceof ParentCommunicationMessage) {
                if ($existing->request_fingerprint !== $fingerprint) {
                    throw ValidationException::withMessages(['Idempotency-Key' => ['The idempotency key was already used for a different message.']]);
                }

                return $existing;
            }
        }
        $message = DB::transaction(fn (): ParentCommunicationMessage => $this->createMessage($thread, $actor, trim($body), $hash, $fingerprint));
        $this->auditMutation($actor, 'parent.communication_message_created', (string) $message->public_id, 'communications.parent.manage');

        return $message;
    }

    private function createMessage(ParentCommunicationThread $thread, UserIdentity $actor, string $body, ?string $hash = null, ?string $fingerprint = null): ParentCommunicationMessage
    {
        return ParentCommunicationMessage::query()->create(['school_id' => $thread->school_id, 'thread_id' => $thread->getKey(), 'author_id' => $actor->getKey(), 'body' => $body, 'idempotency_key_hash' => $hash, 'request_fingerprint' => $fingerprint]);
    }

    private function staffContext(UserIdentity $actor, string $school, string $permission): TenantContext
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }

        return $context;
    }

    /** @return Collection<int, \stdClass> */
    private function guardianLinks(UserIdentity $actor, ?string $student = null): Collection
    {
        return DB::table('student_guardian_relationships')->join('guardian_profiles', 'guardian_profiles.id', '=', 'student_guardian_relationships.guardian_profile_id')->join('students', 'students.id', '=', 'student_guardian_relationships.student_id')->join('schools', 'schools.id', '=', 'student_guardian_relationships.school_id')->leftJoin('student_enrollments', function ($join): void {
            $join->on('student_enrollments.student_id', '=', 'students.id')->on('student_enrollments.school_id', '=', 'students.school_id')->where('student_enrollments.status', '=', 'active');
        })->where('guardian_profiles.user_id', $actor->getKey())->where('student_guardian_relationships.status', 'active')->whereNotNull('student_guardian_relationships.verified_at')->where('students.status', 'active')->where('schools.status', 'active')->when($student !== null, fn ($query) => $query->where('students.public_id', $student))->select(['student_guardian_relationships.school_id', 'student_guardian_relationships.student_id', 'student_guardian_relationships.guardian_profile_id', 'students.public_id as student_public_id', 'students.display_name', 'schools.public_id as school_public_id', 'schools.name as school_name', 'student_enrollments.academic_class_arm_id'])->get();
    }

    /**
     * @param  Collection<int, \stdClass>  $links
     * @return list<array<string, mixed>>
     */
    private function announcements(Collection $links): array
    {
        /** @var array<string, array<string, mixed>> $items */
        $items = [];
        foreach ($links as $link) {
            $query = TeachingAnnouncement::query()->where('school_id', $link->school_id)->where('status', 'published')->where(function ($scope) use ($link): void {
                $scope->where('academic_class_arm_id', $link->academic_class_arm_id)->orWhereIn('academic_subject_offering_id', DB::table('academic_subject_offerings')->where('school_id', $link->school_id)->where('academic_class_arm_id', $link->academic_class_arm_id)->pluck('id'));
            })->orderByDesc('published_at')->limit(50)->get();
            foreach ($query as $announcement) {
                $items[(string) $announcement->public_id] = ['id' => (string) $announcement->public_id, 'title' => (string) $announcement->title, 'body' => (string) $announcement->body, 'school_id' => (string) $link->school_public_id, 'school_name' => (string) $link->school_name, 'student_id' => (string) $link->student_public_id, 'published_at' => $this->timestamp($announcement->published_at)];
            }
        }

        return array_values($items);
    }

    /** @return list<int> */
    private function staffStudentIds(UserIdentity $actor, TenantContext $context): array
    {
        $classArms = DB::table('class_teacher_assignments')->where('school_id', $context->schoolId)->where('school_membership_id', $context->membershipId)->where('status', 'active')->pluck('academic_class_arm_id');
        $offeringArms = DB::table('academic_teaching_assignments')->join('academic_subject_offerings', 'academic_subject_offerings.id', '=', 'academic_teaching_assignments.academic_subject_offering_id')->where('academic_teaching_assignments.school_id', $context->schoolId)->where('academic_teaching_assignments.teacher_id', $actor->getKey())->where('academic_teaching_assignments.status', 'active')->pluck('academic_subject_offerings.academic_class_arm_id');
        $armIds = $classArms->merge($offeringArms)->unique()->values();

        return DB::table('student_enrollments')->where('school_id', $context->schoolId)->where('status', 'active')->whereIn('academic_class_arm_id', $armIds)->pluck('student_id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    private function staffCanAccess(UserIdentity $actor, TenantContext $context, int $studentId): bool
    {
        return $this->isLeadership($actor, $context->membershipId) || in_array($studentId, $this->staffStudentIds($actor, $context), true);
    }

    private function isLeadership(UserIdentity $actor, ?int $membershipId): bool
    {
        return $membershipId !== null && SchoolMembership::query()->whereKey($membershipId)->where('user_id', $actor->getKey())->whereHas('roleAssignments', fn ($query) => $query->whereNull('revoked_at')->whereHas('role', fn ($role) => $role->whereIn('key', ['school_admin', 'principal', 'proprietor', 'hod_reviewer'])))->exists();
    }

    /** @return array<string, mixed> */
    private function threadData(ParentCommunicationThread $thread, bool $withMessages = false): array
    {
        $scope = DB::table('students')->join('schools', 'schools.id', '=', 'students.school_id')->where('students.id', $thread->student_id)->where('schools.id', $thread->school_id)->select(['students.public_id as student_public_id', 'students.display_name', 'schools.public_id as school_public_id', 'schools.name as school_name'])->first();
        $data = ['id' => (string) $thread->public_id, 'student_id' => (string) ($scope === null ? '' : $scope->student_public_id), 'student_name' => (string) ($scope === null ? '' : $scope->display_name), 'school_id' => (string) ($scope === null ? '' : $scope->school_public_id), 'school_name' => (string) ($scope === null ? '' : $scope->school_name), 'title' => (string) $thread->title, 'status' => (string) $thread->status, 'message_count' => $thread->messages_count ?? $thread->messages->count(), 'created_at' => $this->timestamp($thread->created_at), 'closed_at' => $this->timestamp($thread->closed_at)];
        if ($withMessages) {
            $data['messages'] = $thread->messages->sortBy('id')->values()->map(fn (ParentCommunicationMessage $message): array => $this->messageData($message))->all();
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function messageData(ParentCommunicationMessage $message): array
    {
        return ['id' => (string) $message->public_id, 'author_id' => (string) $message->author?->public_id, 'author_name' => (string) $message->author?->name, 'body' => (string) $message->body, 'created_at' => $this->timestamp($message->created_at)];
    }

    private function timestamp(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toISOString() : ($value === null ? null : (string) $value);
    }

    private function auditRead(UserIdentity $actor, int $count, string $caller): void
    {
        $this->audit->execute(new AuditEventData(action: 'parent.communication_read', subjectType: 'parent_communication_projection', actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), metadata: ['caller_type' => $caller, 'result_count' => $count]));
    }

    private function auditMutation(UserIdentity $actor, string $action, string $subject, string $permission): void
    {
        $this->audit->execute(new AuditEventData(action: $action, subjectType: 'parent_communication', subjectPublicId: $subject, actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), metadata: ['permission' => $permission]));
    }
}
