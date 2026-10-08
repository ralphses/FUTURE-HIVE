<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\Actions;

use App\Contexts\Identity\Domain\Enums\ContactType;
use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserContact;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Platform\Domain\Enums\ProvisioningRunStatus;
use App\Contexts\Platform\Domain\Enums\RegistrationContactType;
use App\Contexts\Platform\Domain\Enums\SchoolRegistrationStatus;
use App\Contexts\Platform\Domain\Models\ProvisioningRun;
use App\Contexts\Platform\Domain\Models\SchoolRegistration;
use App\Contexts\Platform\Domain\Models\SchoolSetupChecklistItem;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class ProvisionSchoolRegistrationAction
{
    /** @var list<string> */
    private const CHECKLIST_KEYS = [
        'school_details',
        'owner_access',
        'school_settings',
        'staff_setup',
    ];

    public function __construct(private readonly RecordAuditEventAction $audit) {}

    /** @return array{registration_id: string, school_id: string, membership_id: string, status: string} */
    public function execute(string $registrationPublicId, ?string $requestId = null): array
    {
        $registration = SchoolRegistration::query()->where('public_id', $registrationPublicId)->first();
        if (! $registration instanceof SchoolRegistration) {
            throw (new ModelNotFoundException)->setModel(SchoolRegistration::class);
        }

        $registrationStatus = (string) $registration->getRawOriginal('status');

        if ($registrationStatus === SchoolRegistrationStatus::Completed->value) {
            return $this->completedResult(
                ProvisioningRun::query()->where('registration_id', $registration->id)->firstOrFail(),
            );
        }

        if ($registrationStatus !== SchoolRegistrationStatus::Verified->value) {
            throw new \RuntimeException('Registration is not ready for provisioning.');
        }

        $run = $this->beginRun($registrationPublicId, $requestId);

        if ((string) $run->getRawOriginal('status') === ProvisioningRunStatus::Completed->value) {
            return $this->completedResult($run);
        }

        try {
            return DB::transaction(function () use ($registrationPublicId, $run, $requestId): array {
                $registration = SchoolRegistration::query()->where('public_id', $registrationPublicId)->lockForUpdate()->firstOrFail();

                if ((string) $registration->getRawOriginal('status') !== SchoolRegistrationStatus::Verified->value) {
                    throw new \RuntimeException('Registration is not ready for provisioning.');
                }

                $registration->update(['status' => SchoolRegistrationStatus::Provisioning]);
                $run = ProvisioningRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();
                $run->update(['status' => ProvisioningRunStatus::Running, 'request_id' => $requestId ?? (string) Str::uuid()]);

                $school = $run->school_id === null
                    ? School::query()->create(['name' => $registration->school_name, 'status' => 'active'])
                    : School::query()->findOrFail($run->school_id);
                $run->update(['school_id' => $school->id]);

                $contactType = $registration->getRawOriginal('contact_type') === RegistrationContactType::Email->value
                    ? ContactType::Email
                    : ContactType::Phone;
                $contact = UserContact::query()->where('type', $contactType)->where('canonical_value', $registration->canonical_contact)->whereNull('superseded_at')->first();
                $identity = $contact?->identity()->first();

                if (! $identity instanceof UserIdentity) {
                    $identity = UserIdentity::query()->create(['name' => 'School Owner', 'password' => null]);
                    $contact = $identity->contacts()->create([
                        'type' => $contactType,
                        'canonical_value' => $registration->canonical_contact,
                        'is_primary' => true,
                    ]);
                }

                $membership = SchoolMembership::query()->firstOrCreate(
                    ['school_id' => $school->id, 'user_id' => $identity->id, 'status' => 'active'],
                    ['is_owner' => true, 'joined_at' => now()],
                );
                if (! $membership->is_owner) {
                    $membership->update(['is_owner' => true]);
                }

                $role = Role::query()->where('key', 'school_admin')->where('scope', 'school')->where('is_active', true)->firstOrFail();
                $assignment = MembershipRole::query()->firstOrCreate(
                    ['school_membership_id' => $membership->id, 'role_id' => $role->id, 'revoked_at' => null],
                    ['assigned_by' => $identity->id, 'assigned_at' => now()],
                );

                TenantContext::runInternal(
                    TenantContext::fromMembership($membership),
                    'school registration provisioning checklist',
                    function (): null {
                        foreach (self::CHECKLIST_KEYS as $itemKey) {
                            SchoolSetupChecklistItem::query()->firstOrCreate([
                                'item_key' => $itemKey,
                            ], ['status' => 'pending']);
                        }

                        return null;
                    },
                );

                $registration->update(['status' => SchoolRegistrationStatus::Completed]);
                $run->update(['status' => ProvisioningRunStatus::Completed, 'failure_code' => null, 'failure_message' => null]);
                $this->audit->execute(new AuditEventData(
                    action: 'school.registration_provisioned',
                    subjectType: SchoolRegistration::class,
                    subjectPublicId: $registration->public_id,
                    schoolId: $school->id,
                    actorId: null,
                    requestId: $requestId,
                    stateTransition: ['from' => SchoolRegistrationStatus::Verified->value, 'to' => SchoolRegistrationStatus::Completed->value],
                    authorizationContext: ['membership' => $membership->public_id, 'role' => $role->key],
                    metadata: ['school' => $school->public_id, 'membership' => $membership->public_id, 'role_assignment' => $assignment->public_id],
                    requiresSchoolContext: true,
                ));

                return [
                    'registration_id' => $registration->public_id,
                    'school_id' => $school->public_id,
                    'membership_id' => $membership->public_id,
                    'status' => SchoolRegistrationStatus::Completed->value,
                ];
            }, 3);
        } catch (Throwable $exception) {
            ProvisioningRun::query()->whereKey($run->getKey())->update([
                'status' => ProvisioningRunStatus::Failed,
                'failure_code' => 'PROVISIONING_FAILED',
                'failure_message' => 'Provisioning could not be completed.',
            ]);

            throw $exception;
        }
    }

    private function beginRun(string $registrationPublicId, ?string $requestId): ProvisioningRun
    {
        $registration = SchoolRegistration::query()->where('public_id', $registrationPublicId)->first();
        if (! $registration instanceof SchoolRegistration) {
            throw (new ModelNotFoundException)->setModel(SchoolRegistration::class);
        }

        $run = DB::transaction(function () use ($registration, $requestId): ProvisioningRun {
            $run = ProvisioningRun::query()->where('registration_id', $registration->id)->lockForUpdate()->first();
            if (! $run instanceof ProvisioningRun) {
                $run = ProvisioningRun::query()->create([
                    'registration_id' => $registration->id,
                    'status' => ProvisioningRunStatus::Running,
                    'attempts' => 0,
                    'request_id' => $requestId ?? (string) Str::uuid(),
                ]);
            }
            $run->increment('attempts');
            $run->refresh();

            return $run;
        }, 3);

        return $run;
    }

    /** @return array{registration_id: string, school_id: string, membership_id: string, status: string} */
    private function completedResult(ProvisioningRun $run): array
    {
        $school = School::query()->findOrFail($run->school_id);
        $membership = SchoolMembership::query()->where('school_id', $school->id)->where('is_owner', true)->where('status', 'active')->firstOrFail();

        return [
            'registration_id' => $run->registration()->value('public_id'),
            'school_id' => $school->public_id,
            'membership_id' => $membership->public_id,
            'status' => SchoolRegistrationStatus::Completed->value,
        ];
    }
}
