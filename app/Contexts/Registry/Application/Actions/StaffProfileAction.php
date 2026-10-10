<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Application\Actions;

use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Registry\Domain\Models\StaffProfile;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StaffProfileAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return array{items: list<array<string, mixed>>} */
    public function index(UserIdentity $actor, string $school): array
    {
        $this->authorize($actor, $school, 'staff.read');

        return ['items' => StaffProfile::query()->with('membership.identity')->orderBy('staff_number')->get()->map(fn (StaffProfile $profile): array => $this->data($profile))->all()];
    }

    /** @return array<string, mixed> */
    public function show(UserIdentity $actor, string $school, string $staff): array
    {
        $this->authorize($actor, $school, 'staff.read');

        return $this->data($this->find($staff));
    }

    /** @param array{membership_id: string, staff_number: string, legal_name: string, preferred_name?: string|null, job_title?: string|null, department?: string|null} $input
     * @return array<string, mixed>
     */
    public function create(UserIdentity $actor, string $school, array $input): array
    {
        $this->authorize($actor, $school, 'staff.manage');

        return DB::transaction(function () use ($actor, $input): array {
            $membership = $this->findActiveMembership((string) $input['membership_id'], true);
            if (StaffProfile::query()->where('school_membership_id', $membership->getKey())->exists()) {
                throw ValidationException::withMessages(['membership_id' => ['A staff profile already exists for this membership.']]);
            }

            $profile = StaffProfile::query()->create([
                'school_membership_id' => $membership->getKey(),
                'staff_number' => trim($input['staff_number']),
                'legal_name' => trim($input['legal_name']),
                'preferred_name' => $this->nullableTrim($input['preferred_name'] ?? null),
                'job_title' => $this->nullableTrim($input['job_title'] ?? null),
                'department' => $this->nullableTrim($input['department'] ?? null),
                'employment_status' => 'pending',
            ]);
            $this->record($actor, 'staff.profile_created', (string) $profile->public_id, ['employment_status' => 'pending']);

            return $this->data($profile->load('membership.identity'));
        });
    }

    /** @param array{staff_number: string, legal_name: string, preferred_name?: string|null, job_title?: string|null, department?: string|null} $input
     * @return array<string, mixed>
     */
    public function update(UserIdentity $actor, string $school, string $staff, array $input): array
    {
        $this->authorize($actor, $school, 'staff.manage');

        return DB::transaction(function () use ($actor, $staff, $input): array {
            $profile = $this->find($staff, true);
            if ($profile->employment_status === 'ended') {
                throw ValidationException::withMessages(['staff' => ['Ended employment records cannot be edited.']]);
            }
            $profile->fill([
                'staff_number' => trim($input['staff_number']),
                'legal_name' => trim($input['legal_name']),
                'preferred_name' => $this->nullableTrim($input['preferred_name'] ?? null),
                'job_title' => $this->nullableTrim($input['job_title'] ?? null),
                'department' => $this->nullableTrim($input['department'] ?? null),
            ])->save();
            $this->record($actor, 'staff.profile_updated', (string) $profile->public_id, ['employment_status' => (string) $profile->employment_status]);

            return $this->data($profile->load('membership.identity'));
        });
    }

    /** @return array<string, mixed> */
    public function transition(UserIdentity $actor, string $school, string $staff, string $status, ?string $reason): array
    {
        $this->authorize($actor, $school, 'staff.employment.manage');
        if (in_array($status, ['suspended', 'ended'], true) && trim((string) $reason) === '') {
            throw ValidationException::withMessages(['reason' => ['A reason is required for this employment transition.']]);
        }

        return DB::transaction(function () use ($actor, $staff, $status, $reason): array {
            $profile = $this->find($staff, true);
            $from = (string) $profile->employment_status;
            if ($from === $status) {
                return $this->data($profile->load('membership.identity'));
            }
            if ($from === 'ended' || ($status === 'active' && $from === 'pending' && $profile->membership->status !== 'active')) {
                throw ValidationException::withMessages(['status' => ['The requested employment transition is not allowed.']]);
            }
            if ($status === 'active' && $profile->membership->status !== 'active') {
                throw new ModelNotFoundException;
            }

            $profile->employment_status = $status;
            $profile->status_reason = $reason === null ? null : trim($reason);
            if ($status === 'active') {
                $profile->employment_start_date ??= now()->toDateString();
                $profile->employment_end_date = null;
            }
            if ($status === 'ended') {
                $profile->employment_end_date = now()->toDateString();
            }
            $profile->save();
            $this->record($actor, 'staff.employment_'.$status, (string) $profile->public_id, ['from' => $from, 'to' => $status]);

            return $this->data($profile->load('membership.identity'));
        });
    }

    private function authorize(UserIdentity $actor, string $school, string $permission): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
    }

    private function findActiveMembership(string $publicId, bool $lock = false): SchoolMembership
    {
        $query = SchoolMembership::query()->where('public_id', $publicId)->where('school_id', TenantContext::require()->schoolId)->where('status', 'active');
        if ($lock) {
            $query->lockForUpdate();
        }
        $membership = $query->first();
        if (! $membership instanceof SchoolMembership) {
            throw new ModelNotFoundException;
        }

        return $membership;
    }

    private function find(string $publicId, bool $lock = false): StaffProfile
    {
        $query = StaffProfile::query()->where('public_id', $publicId)->with('membership.identity');
        if ($lock) {
            $query->lockForUpdate();
        }
        $profile = $query->first();
        if (! $profile instanceof StaffProfile) {
            throw new ModelNotFoundException;
        }

        return $profile;
    }

    /**
     * @param  array<string, mixed>  $transition
     */
    private function record(UserIdentity $actor, string $action, string $publicId, array $transition): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(action: $action, subjectType: 'staff_profile', subjectPublicId: $publicId, schoolId: $context->schoolId, actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), authorizationContext: ['scope' => 'school_administration'], stateTransition: $transition, requiresSchoolContext: true));
    }

    /** @return array<string, mixed> */
    private function data(StaffProfile $profile): array
    {
        $membership = $profile->membership;
        $identity = $membership?->identity;

        return [
            'id' => (string) $profile->public_id,
            'membership_id' => (string) $membership?->public_id,
            'staff_number' => (string) $profile->staff_number,
            'legal_name' => (string) $profile->legal_name,
            'preferred_name' => $profile->preferred_name === null ? null : (string) $profile->preferred_name,
            'job_title' => $profile->job_title === null ? null : (string) $profile->job_title,
            'department' => $profile->department === null ? null : (string) $profile->department,
            'employment_status' => (string) $profile->employment_status,
            'employment_start_date' => $profile->employment_start_date === null ? null : CarbonImmutable::parse((string) $profile->employment_start_date)->toDateString(),
            'employment_end_date' => $profile->employment_end_date === null ? null : CarbonImmutable::parse((string) $profile->employment_end_date)->toDateString(),
            'identity_name' => $identity?->name === null ? null : (string) $identity->name,
        ];
    }

    private function nullableTrim(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
