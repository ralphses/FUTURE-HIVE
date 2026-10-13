<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Application\Actions;

use App\Contexts\Finance\Domain\Models\FeeInvoice;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Registry\Domain\Models\GuardianProfile;
use App\Contexts\Registry\Domain\Models\StudentGuardianRelationship;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;

final class FamilyBillingAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return array{families: list<array<string, mixed>>} */
    public function forSchool(UserIdentity $actor, string $school, ?string $guardian = null): array
    {
        $this->authorize($actor, $school, 'finance.family-billing.read');

        $context = TenantContext::require();
        $relationships = $this->relationships($context->schoolId, $guardian);
        $result = $this->families($relationships, $context->schoolId);
        $this->auditRead($actor, $context->schoolId, count($result));

        return ['families' => $result];
    }

    /** @return array{families: list<array<string, mixed>>} */
    public function forGuardian(UserIdentity $actor): array
    {
        $context = TenantContext::require();
        if (! $this->permissions->allows($actor, $context->schoolPublicId, 'finance.family-billing.read')) {
            throw new ModelNotFoundException;
        }

        $profile = GuardianProfile::query()
            ->where('user_id', $actor->getAuthIdentifier())
            ->whereHas('identity.contacts', fn ($query) => $query->whereNotNull('verified_at')->whereNull('superseded_at'))
            ->first();
        if (! $profile instanceof GuardianProfile) {
            throw new ModelNotFoundException;
        }

        $relationships = $this->relationships($context->schoolId, null, $profile->getKey());
        $result = $this->families($relationships, $context->schoolId);
        $this->auditRead($actor, $context->schoolId, count($result));

        return ['families' => $result];
    }

    /** @return list<StudentGuardianRelationship> */
    private function relationships(int $schoolId, ?string $guardian, ?int $profileId = null): array
    {
        $query = StudentGuardianRelationship::query()
            ->where('school_id', $schoolId)
            ->where('status', 'active')
            ->whereNotNull('verified_at')
            ->whereHas('guardianProfile.identity.contacts', fn ($contactQuery) => $contactQuery->whereNotNull('verified_at')->whereNull('superseded_at'))
            ->with(['guardianProfile.identity', 'student']);

        if ($guardian !== null) {
            $identity = UserIdentity::query()->where('public_id', $guardian)->first();
            if (! $identity instanceof UserIdentity) {
                throw new ModelNotFoundException;
            }
            $query->whereHas('guardianProfile', fn ($profileQuery) => $profileQuery->where('user_id', $identity->getKey()));
        }
        if ($profileId !== null) {
            $query->where('guardian_profile_id', $profileId);
        }

        return $query->orderBy('guardian_profile_id')->orderBy('student_id')->get()->all();
    }

    /** @param list<StudentGuardianRelationship> $relationships
     * @return list<array<string, mixed>>
     */
    private function families(array $relationships, int $schoolId): array
    {
        if ($relationships === []) {
            return [];
        }

        $studentIds = collect($relationships)->pluck('student_id')->unique()->values();
        $invoices = FeeInvoice::query()
            ->where('school_id', $schoolId)
            ->whereIn('student_id', $studentIds)
            ->whereHas('enrollment', fn ($query) => $query->whereIn('status', ['active', 'ended']))
            ->with(['student', 'lines', 'adjustments' => fn ($query) => $query->where('status', 'applied')->with('program')])
            ->orderBy('student_id')
            ->orderBy('id')
            ->get()
            ->groupBy('student_id');

        return collect($relationships)
            ->groupBy('guardian_profile_id')
            ->map(function ($items) use ($invoices): array {
                /** @var StudentGuardianRelationship $first */
                $first = $items->first();
                $identity = $first->guardianProfile?->identity;

                return [
                    'guardian_id' => (string) $identity?->public_id,
                    'students' => $items->map(function (StudentGuardianRelationship $relationship) use ($invoices): array {
                        $student = $relationship->student;

                        return [
                            'student_id' => (string) $student?->public_id,
                            'student_number' => (string) $student?->student_number,
                            'display_name' => (string) $student?->display_name,
                            'relationship_type' => (string) $relationship->relationship_type,
                            'invoices' => ($invoices->get($relationship->student_id, collect()))->map(fn (FeeInvoice $invoice): array => $this->invoiceData($invoice))->values()->all(),
                        ];
                    })->values()->all(),
                ];
            })->values()->all();
    }

    /** @return array<string, mixed> */
    private function invoiceData(FeeInvoice $invoice): array
    {
        return [
            'id' => (string) $invoice->public_id,
            'invoice_number' => (string) $invoice->invoice_number,
            'currency' => (string) $invoice->currency,
            'total_minor_units' => (int) $invoice->total_minor_units,
            'due_date' => (string) $invoice->due_date,
            'status' => (string) $invoice->status,
            'lines' => $invoice->lines->map(fn ($line): array => [
                'id' => (string) $line->public_id,
                'category_code' => (string) $line->category_code,
                'category_name' => (string) $line->category_name,
                'amount_minor_units' => (int) $line->amount_minor_units,
                'sequence' => (int) $line->sequence,
            ])->values()->all(),
            'adjustments' => $invoice->adjustments->map(fn ($adjustment): array => [
                'id' => (string) $adjustment->public_id,
                'type' => (string) $adjustment->adjustment_type,
                'amount_minor_units' => (int) $adjustment->amount_minor_units,
                'status' => (string) $adjustment->status,
            ])->values()->all(),
        ];
    }

    private function authorize(UserIdentity $actor, string $school, string $permission): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
    }

    private function auditRead(UserIdentity $actor, int $schoolId, int $familyCount): void
    {
        $this->audit->execute(new AuditEventData(
            action: 'finance.family_billing_read',
            subjectType: 'family_billing_projection',
            subjectPublicId: null,
            schoolId: $schoolId,
            actorId: (int) $actor->getAuthIdentifier(),
            requestId: Context::get('request_id'),
            metadata: ['family_count' => $familyCount],
            requiresSchoolContext: true,
        ));
    }
}
