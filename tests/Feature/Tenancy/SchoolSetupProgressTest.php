<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Domain\Models\SchoolSetupChecklistItem;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SchoolSetupProgressTest extends TestCase
{
    use RefreshDatabase;

    private string $privateKey = '';

    private string $publicKey = '';

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $this->privateKey));
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $this->publicKey = (string) ($details['key'] ?? '');
        config([
            'auth.jwt.private_key' => $this->privateKey,
            'auth.jwt.public_keys' => ['test-key' => $this->publicKey],
            'auth.jwt.current_kid' => 'test-key',
            'auth.jwt.issuer' => 'https://schoolos.test',
            'auth.jwt.audience' => 'schoolos-api',
        ]);
    }

    public function test_school_admin_can_view_and_update_resumable_setup_progress(): void
    {
        [$school, $identity] = $this->schoolWithRole();
        $this->seedChecklist($school, $identity);
        $token = $this->loginAndSelect($identity, $school);

        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/setup')
            ->assertOk()
            ->assertJsonPath('data.total_count', 4)
            ->assertJsonPath('data.completed_count', 0)
            ->assertJsonPath('data.completion_percentage', 0)
            ->assertJsonPath('data.items.0.key', 'owner_access');

        $this->withToken($token)->patchJson('/api/v1/schools/'.$school->public_id.'/setup/school_details', ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertDatabaseHas('school_setup_checklist_items', [
            'school_id' => $school->id,
            'item_key' => 'school_details',
            'status' => 'completed',
        ]);
    }

    public function test_completed_setup_item_can_be_reopened_and_unknown_items_are_hidden(): void
    {
        [$school, $identity] = $this->schoolWithRole();
        $this->seedChecklist($school, $identity);
        $token = $this->loginAndSelect($identity, $school);

        $this->withToken($token)->patchJson('/api/v1/schools/'.$school->public_id.'/setup/school_details', ['status' => 'completed'])->assertOk();
        $this->withToken($token)->patchJson('/api/v1/schools/'.$school->public_id.'/setup/school_details', ['status' => 'pending'])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.completed_at', null);

        $this->withToken($token)->patchJson('/api/v1/schools/'.$school->public_id.'/setup/not-approved', ['status' => 'completed'])
            ->assertNotFound();
    }

    public function test_setup_progress_requires_selected_context_and_settings_permission(): void
    {
        [$school, $identity] = $this->schoolWithRole();
        $this->seedChecklist($school, $identity);
        $token = $this->login($identity);

        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/setup')->assertForbidden();

        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();
        $this->withToken($token)->patchJson('/api/v1/schools/'.$school->public_id.'/setup/school_details', ['status' => 'completed'])->assertOk();
    }

    public function test_cross_school_and_client_owned_fields_cannot_override_context(): void
    {
        [$school, $identity] = $this->schoolWithRole();
        $otherSchool = School::factory()->create(['name' => 'Other Setup School']);
        $this->seedChecklist($school, $identity);
        $token = $this->loginAndSelect($identity, $school);

        $this->withToken($token)->getJson('/api/v1/schools/'.$otherSchool->public_id.'/setup')->assertNotFound();
        $this->withToken($token)->patchJson('/api/v1/schools/'.$school->public_id.'/setup/school_details', [
            'status' => 'completed',
            'school_id' => $otherSchool->id,
            'user_id' => 999999,
            'completed_at' => '2030-01-01T00:00:00Z',
        ])->assertOk();

        $item = SchoolSetupChecklistItem::query()->where('school_id', $school->id)->where('item_key', 'school_details')->firstOrFail();
        self::assertSame($school->id, $item->school_id);
    }

    /** @return array{0: School, 1: UserIdentity} */
    private function schoolWithRole(): array
    {
        $school = School::factory()->create(['name' => 'Fictional Setup Academy']);
        $identity = UserIdentity::factory()->withPassword('setup-password')->create();
        $membership = SchoolMembership::factory()->create(['school_id' => $school->id, 'user_id' => $identity->id]);
        MembershipRole::create([
            'school_membership_id' => $membership->id,
            'role_id' => Role::query()->where('key', 'school_admin')->value('id'),
            'assigned_by' => $identity->id,
            'assigned_at' => now(),
        ]);

        return [$school, $identity];
    }

    private function seedChecklist(School $school, UserIdentity $identity): void
    {
        $membership = SchoolMembership::query()->where('school_id', $school->id)->where('user_id', $identity->id)->firstOrFail();
        TenantContext::runInternal(TenantContext::fromMembership($membership), 'setup progress fixture', static function (): void {
            foreach (['school_details', 'owner_access', 'school_settings', 'staff_setup'] as $itemKey) {
                SchoolSetupChecklistItem::query()->create(['item_key' => $itemKey, 'status' => 'pending']);
            }
        });
    }

    private function login(UserIdentity $identity): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'login' => $identity->contacts()->firstOrFail()->canonical_value,
            'password' => 'setup-password',
        ])->assertOk()->json('data.access_token');
    }

    private function loginAndSelect(UserIdentity $identity, School $school): string
    {
        $token = $this->login($identity);
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return $token;
    }
}
