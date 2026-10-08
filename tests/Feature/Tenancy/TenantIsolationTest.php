<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Domain\Models\SchoolSetupChecklistItem;
use App\Support\Tenancy\MissingTenantContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use InvalidArgumentException;
use Tests\TestCase;

final class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_school_owned_records_are_scoped_to_the_trusted_school(): void
    {
        [$firstMembership, $secondMembership] = $this->membershipsForTwoSchools();
        $firstItem = $this->createChecklistItem($firstMembership, 'school_details');
        $secondItem = $this->createChecklistItem($secondMembership, 'school_details');

        TenantContext::runInternal(TenantContext::fromMembership($firstMembership), 'tenant isolation test', function () use ($firstItem, $secondItem): null {
            self::assertNotNull(SchoolSetupChecklistItem::query()->find($firstItem->id));
            self::assertNull(SchoolSetupChecklistItem::query()->find($secondItem->id));
            self::assertSame(0, SchoolSetupChecklistItem::query()->whereKey($secondItem->id)->update(['status' => 'complete']));
            self::assertSame(0, SchoolSetupChecklistItem::query()->whereKey($secondItem->id)->delete());

            return null;
        });

        self::assertSame('pending', $secondItem->fresh()->status);
    }

    public function test_missing_context_fails_closed(): void
    {
        $this->membershipsForTwoSchools();

        $this->expectException(MissingTenantContext::class);
        SchoolSetupChecklistItem::query()->count();
    }

    public function test_creation_uses_trusted_school_instead_of_client_supplied_school(): void
    {
        [$firstMembership, $secondMembership] = $this->membershipsForTwoSchools();

        TenantContext::runInternal(TenantContext::fromMembership($firstMembership), 'tenant ownership test', function () use ($secondMembership): null {
            $this->expectException(InvalidArgumentException::class);
            SchoolSetupChecklistItem::query()->create([
                'school_id' => $secondMembership->school_id,
                'item_key' => 'owner_access',
                'status' => 'pending',
            ]);

            return null;
        });
    }

    public function test_mismatched_school_creation_and_ownership_changes_are_rejected(): void
    {
        [$firstMembership, $secondMembership] = $this->membershipsForTwoSchools();
        $item = $this->createChecklistItem($firstMembership, 'school_details');

        TenantContext::runInternal(TenantContext::fromMembership($firstMembership), 'tenant ownership test', function () use ($item, $secondMembership): null {
            $this->expectException(InvalidArgumentException::class);
            $item->school_id = $secondMembership->school_id;
            $item->save();

            return null;
        });
    }

    public function test_same_business_key_is_allowed_in_different_schools(): void
    {
        [$firstMembership, $secondMembership] = $this->membershipsForTwoSchools();
        $firstItem = $this->createChecklistItem($firstMembership, 'school_details');
        $secondItem = $this->createChecklistItem($secondMembership, 'school_details');

        self::assertNotSame($firstItem->school_id, $secondItem->school_id);
        self::assertSame('school_details', $firstItem->item_key);
        self::assertSame('school_details', $secondItem->item_key);
    }

    public function test_global_school_records_remain_available_without_tenant_scope(): void
    {
        [$firstMembership, $secondMembership] = $this->membershipsForTwoSchools();
        Context::forgetHidden('tenant_context');

        self::assertSame(2, School::query()->whereIn('id', [$firstMembership->school_id, $secondMembership->school_id])->count());
    }

    public function test_inactive_membership_cannot_establish_internal_tenant_context(): void
    {
        [$membership] = $this->membershipsForTwoSchools();
        $membership->update(['status' => 'revoked']);

        $this->expectException(InvalidArgumentException::class);
        TenantContext::fromMembership($membership->fresh());
    }

    /** @return array{0: SchoolMembership, 1: SchoolMembership} */
    private function membershipsForTwoSchools(): array
    {
        $identity = UserIdentity::factory()->create();
        $firstSchool = School::factory()->create(['name' => 'Isolation School One']);
        $secondSchool = School::factory()->create(['name' => 'Isolation School Two']);

        return [
            SchoolMembership::factory()->create(['user_id' => $identity->id, 'school_id' => $firstSchool->id]),
            SchoolMembership::factory()->create(['user_id' => $identity->id, 'school_id' => $secondSchool->id]),
        ];
    }

    private function createChecklistItem(SchoolMembership $membership, string $itemKey): SchoolSetupChecklistItem
    {
        return TenantContext::runInternal(TenantContext::fromMembership($membership), 'tenant isolation fixture', static fn (): SchoolSetupChecklistItem => SchoolSetupChecklistItem::query()->create([
            'item_key' => $itemKey,
            'status' => 'pending',
        ]));
    }
}
