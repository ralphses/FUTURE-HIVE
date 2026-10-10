<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AcademicPromotionRuleTest extends TestCase
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
        config(['auth.jwt.private_key' => $this->privateKey, 'auth.jwt.public_keys' => ['test-key' => $this->publicKey], 'auth.jwt.current_kid' => 'test-key', 'auth.jwt.issuer' => 'https://schoolos.test', 'auth.jwt.audience' => 'schoolos-api']);
    }

    public function test_valid_rule_is_created_with_uuid_public_ids_and_criteria(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'promotion-create@example.com', 'promotion-password');
        $token = $this->loginAndSelect($admin, $school, 'promotion-password');
        [$source, $target] = $this->levels($school, $token);

        $response = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/promotion-rules', $this->payload($source, $target))->assertCreated();

        $response->assertJsonPath('data.status', 'draft')->assertJsonCount(2, 'data.criteria');
        self::assertTrue(Str::isUuid((string) $response->json('data.id')));
        $this->assertDatabaseHas('academic_promotion_rule_sets', ['school_id' => $school->id, 'status' => 'draft']);
    }

    public function test_invalid_criteria_are_rejected_without_creating_a_rule(): void
    {
        [$school, $admin] = $this->schoolWithRole('proprietor', 'promotion-validation@example.com', 'promotion-password');
        $token = $this->loginAndSelect($admin, $school, 'promotion-password');
        [$source, $target] = $this->levels($school, $token);
        $payload = $this->payload($source, $target);
        $payload['criteria'][0]['metric'] = 'unsupported_metric';

        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/promotion-rules', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('academic_promotion_rule_sets', 0);
    }

    public function test_only_one_active_rule_can_exist_for_a_level_pair(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'promotion-active@example.com', 'promotion-password');
        $token = $this->loginAndSelect($admin, $school, 'promotion-password');
        [$source, $target] = $this->levels($school, $token);
        $path = '/api/v1/schools/'.$school->public_id.'/promotion-rules';
        $first = $this->withToken($token)->postJson($path, $this->payload($source, $target))->assertCreated()->json('data.id');
        $second = $this->withToken($token)->postJson($path, $this->payload($source, $target, 'Another rule'))->assertCreated()->json('data.id');

        $this->withToken($token)->postJson($path.'/'.$first.'/activate')->assertOk();
        $this->withToken($token)->postJson($path.'/'.$second.'/activate')->assertUnprocessable();
        $this->withToken($token)->postJson($path.'/'.$first.'/activate')->assertOk();
    }

    public function test_cross_school_rule_reads_are_not_confirmed(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'promotion-scope@example.com', 'promotion-password');
        $token = $this->loginAndSelect($admin, $school, 'promotion-password');
        [$source, $target] = $this->levels($school, $token);
        $rule = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/promotion-rules', $this->payload($source, $target))->assertCreated()->json('data.id');
        [$otherSchool, $otherAdmin] = $this->schoolWithRole('school_admin', 'promotion-other@example.com', 'promotion-password');
        $otherToken = $this->loginAndSelect($otherAdmin, $otherSchool, 'promotion-password');

        $this->withToken($otherToken)->getJson('/api/v1/schools/'.$otherSchool->public_id.'/promotion-rules/'.$rule)->assertNotFound();
    }

    /** @return array{0: string, 1: string} */
    private function levels(School $school, string $token): array
    {
        $base = '/api/v1/schools/'.$school->public_id.'/academic-levels';
        $source = $this->withToken($token)->postJson($base, ['name' => 'Primary Stage', 'code' => 'PS', 'sequence' => 1])->assertCreated()->json('data.id');
        $target = $this->withToken($token)->postJson($base, ['name' => 'Junior Stage', 'code' => 'JS', 'sequence' => 2])->assertCreated()->json('data.id');

        return [$source, $target];
    }

    /** @return array<string, mixed> */
    private function payload(string $source, string $target, string $name = 'Standard promotion rule'): array
    {
        return ['source_level_id' => $source, 'target_level_id' => $target, 'name' => $name, 'description' => 'Fictional school progression criteria.', 'criteria' => [['metric' => 'overall_percentage', 'operator' => 'gte', 'threshold' => 50, 'required' => true, 'sequence' => 1], ['metric' => 'attendance_percentage', 'operator' => 'gte', 'threshold' => 75, 'required' => false, 'sequence' => 2]]];
    }

    /** @return array{0: School, 1: UserIdentity} */
    private function schoolWithRole(string $roleKey, string $email, string $password): array
    {
        $school = School::factory()->create(['name' => 'Fictional Promotion School']);
        $identity = UserIdentity::factory()->withPassword($password)->create();
        $identity->contacts()->update(['canonical_value' => $email, 'verified_at' => now()]);
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $identity->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', $roleKey)->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);

        return [$school, $identity];
    }

    private function loginAndSelect(UserIdentity $identity, School $school, string $password): string
    {
        $token = $this->postJson('/api/v1/auth/login', ['login' => $identity->contacts()->firstOrFail()->canonical_value, 'password' => $password])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return $token;
    }
}
