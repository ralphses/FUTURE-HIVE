<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Contexts\Identity\Application\Actions\ProvisionSchoolRegistrationAction;
use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Domain\Enums\SchoolRegistrationStatus;
use App\Contexts\Platform\Domain\Models\AuditEvent;
use App\Contexts\Platform\Domain\Models\SchoolRegistration;
use App\Contexts\Platform\Domain\Models\SchoolSetupChecklistItem;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SchoolProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_registration_is_provisioned_idempotently(): void
    {
        $registration = $this->registration(SchoolRegistrationStatus::Verified, 'owner@example.com');

        $action = $this->app->make(ProvisionSchoolRegistrationAction::class);
        $first = $action->execute($registration->public_id);
        $second = $action->execute($registration->public_id);

        self::assertSame($first, $second);
        self::assertSame('completed', $first['status']);
        $this->assertDatabaseCount('schools', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('school_memberships', 1);
        $this->assertDatabaseCount('membership_roles', 1);
        $this->assertDatabaseCount('provisioning_runs', 1);
        $membership = SchoolMembership::query()->with('school')->firstOrFail();
        self::assertSame(4, TenantContext::runInternal(
            TenantContext::fromMembership($membership),
            'school provisioning verification',
            static fn (): int => SchoolSetupChecklistItem::query()->count(),
        ));
        self::assertSame('school_admin', MembershipRole::query()->firstOrFail()->role->key);
    }

    public function test_existing_identity_is_reused_without_changing_iam_verification(): void
    {
        $identity = UserIdentity::factory()->create();
        $contact = $identity->contacts()->firstOrFail();
        $contact->update(['canonical_value' => 'owner@example.com', 'verified_at' => null]);
        $registration = $this->registration(SchoolRegistrationStatus::Verified, 'owner@example.com');

        $this->app->make(ProvisionSchoolRegistrationAction::class)->execute($registration->public_id);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('user_contacts', ['user_id' => $identity->id, 'verified_at' => null]);
    }

    public function test_verified_international_phone_registration_creates_a_credentialless_owner(): void
    {
        $registration = $this->registration(SchoolRegistrationStatus::Verified, '+2348012345678', 'phone');

        $this->app->make(ProvisionSchoolRegistrationAction::class)->execute($registration->public_id);

        $this->assertDatabaseHas('user_contacts', [
            'type' => 'phone',
            'canonical_value' => '+2348012345678',
        ]);
        $this->assertDatabaseHas('users', ['name' => 'School Owner', 'password' => null]);
        $this->assertDatabaseCount('auth_sessions', 0);
    }

    public function test_provisioning_audit_contains_references_but_no_contact_or_credentials(): void
    {
        $registration = $this->registration(SchoolRegistrationStatus::Verified, 'private-owner@example.com');

        $this->app->make(ProvisionSchoolRegistrationAction::class)->execute($registration->public_id);

        $audit = json_encode(AuditEvent::query()->latest('id')->firstOrFail()->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('private-owner@example.com', $audit);
        self::assertStringNotContainsString('password', strtolower($audit));
        self::assertStringNotContainsString('token', strtolower($audit));
    }

    public function test_unverified_registration_does_not_create_provisioning_records(): void
    {
        $registration = $this->registration(SchoolRegistrationStatus::PendingVerification, 'pending@example.com');

        try {
            $this->app->make(ProvisionSchoolRegistrationAction::class)->execute($registration->public_id);
            self::fail('Expected provisioning to reject an unverified registration.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Registration is not ready for provisioning.', $exception->getMessage());
        }

        $this->assertDatabaseCount('schools', 0);
        $this->assertDatabaseCount('provisioning_runs', 0);
    }

    public function test_provisioning_has_no_public_api_route(): void
    {
        $this->assertFalse(collect(app('router')->getRoutes()->getRoutes())->contains(
            static fn ($route): bool => Str::contains($route->uri(), 'provision'),
        ));
    }

    private function registration(SchoolRegistrationStatus $status, string $contact, string $contactType = 'email'): SchoolRegistration
    {
        return SchoolRegistration::query()->create([
            'school_name' => 'Fictional Academy',
            'school_type' => 'secondary',
            'state' => 'Lagos',
            'contact_type' => $contactType,
            'canonical_contact' => $contact,
            'consent_version' => 'v1',
            'consented_at' => now(),
            'status' => $status,
        ]);
    }
}
