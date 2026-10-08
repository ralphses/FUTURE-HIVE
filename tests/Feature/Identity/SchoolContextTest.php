<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Contexts\Identity\Domain\Models\AuthSession;
use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Identity\Infrastructure\Authentication\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Lcobucci\JWT\Token\Plain;
use Tests\TestCase;

final class SchoolContextTest extends TestCase
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

    public function test_active_membership_can_be_selected_and_viewed_without_changing_jwt_claims(): void
    {
        [$identity, $firstSchool] = $this->identityWithMemberships();
        $token = $this->login($identity, 'context-password')->json('data.access_token');

        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $firstSchool->public_id])
            ->assertOk()
            ->assertJsonPath('data.school_id', $firstSchool->public_id);
        $context = $this->withToken($token)->getJson('/api/v1/auth/context')
            ->assertOk()
            ->assertJsonPath('data.school_id', $firstSchool->public_id);

        self::assertArrayNotHasKey('school_id', $this->parseToken($token)->claims()->all());
    }

    public function test_context_selection_is_independent_per_authentication_session(): void
    {
        [$identity, $firstSchool, $secondSchool] = $this->identityWithMemberships();
        $firstToken = $this->login($identity, 'context-password')->json('data.access_token');
        $secondToken = $this->login($identity, 'context-password')->json('data.access_token');

        $this->withToken($firstToken)->postJson('/api/v1/auth/context/switch', ['school_id' => $firstSchool->public_id])->assertOk();
        $this->withToken($secondToken)->postJson('/api/v1/auth/context/switch', ['school_id' => $secondSchool->public_id])->assertOk();

        $this->withToken($firstToken)->getJson('/api/v1/auth/context')->assertJsonPath('data.school_id', $firstSchool->public_id);
        $this->withToken($secondToken)->getJson('/api/v1/auth/context')->assertJsonPath('data.school_id', $secondSchool->public_id);
    }

    public function test_unknown_cross_identity_revoked_and_suspended_contexts_are_denied(): void
    {
        [$identity, $firstSchool] = $this->identityWithMemberships();
        $otherIdentity = UserIdentity::factory()->withPassword('other-password')->create();
        $otherSchool = School::factory()->create(['name' => 'Other Identity School']);
        SchoolMembership::factory()->create(['user_id' => $otherIdentity->id, 'school_id' => $otherSchool->id]);
        $token = $this->login($identity, 'context-password')->json('data.access_token');

        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $firstSchool->public_id])->assertOk();
        $suspendedSchool = School::factory()->create(['status' => 'suspended']);

        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $suspendedSchool->public_id])->assertNotFound();
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $otherSchool->public_id])->assertNotFound();

        SchoolMembership::query()->where('user_id', $identity->id)->where('school_id', $firstSchool->id)->update(['status' => 'revoked']);
        $this->withToken($token)->getJson('/api/v1/auth/context')->assertJsonPath('data', []);
    }

    public function test_school_routes_require_context_but_global_membership_listing_does_not(): void
    {
        [$identity, $school] = $this->identityWithMemberships(true);
        $token = $this->login($identity, 'context-password')->json('data.access_token');

        $this->withToken($token)->getJson('/api/v1/me/schools/'.$school->public_id.'/permissions')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/me/memberships')->assertOk();
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();
        $this->withToken($token)->getJson('/api/v1/me/schools/'.$school->public_id.'/permissions')->assertOk();
    }

    public function test_logout_clears_the_server_side_context(): void
    {
        [$identity, $school] = $this->identityWithMemberships();
        $token = $this->login($identity, 'context-password')->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();
        $session = AuthSession::query()->firstOrFail();

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

        self::assertNull($session->fresh()->active_school_membership_id);
    }

    /** @return array{0: UserIdentity, 1: School, 2?: School} */
    private function identityWithMemberships(bool $withRole = false): array
    {
        $identity = UserIdentity::factory()->withPassword('context-password')->create();
        $firstSchool = School::factory()->create(['name' => 'First Context School']);
        $firstMembership = SchoolMembership::factory()->create(['user_id' => $identity->id, 'school_id' => $firstSchool->id]);
        if ($withRole) {
            MembershipRole::create([
                'school_membership_id' => $firstMembership->id,
                'role_id' => Role::query()->where('key', 'school_admin')->value('id'),
                'assigned_by' => $identity->id,
                'assigned_at' => now(),
            ]);
        }

        $secondSchool = School::factory()->create(['name' => 'Second Context School']);
        SchoolMembership::factory()->create(['user_id' => $identity->id, 'school_id' => $secondSchool->id]);

        return [$identity, $firstSchool, $secondSchool];
    }

    /** @return TestResponse<JsonResponse> */
    private function login(UserIdentity $identity, string $password): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', [
            'login' => $identity->contacts()->firstOrFail()->canonical_value,
            'password' => $password,
        ])->assertOk();
    }

    private function parseToken(string $token): Plain
    {
        return app(JwtTokenService::class)->validate($token);
    }
}
