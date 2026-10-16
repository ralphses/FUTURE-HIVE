<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Domain\Models\Student;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CareerGuidanceTest extends TestCase
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

    public function test_active_student_receives_safe_unavailable_guidance_without_persistence(): void
    {
        [$school, $studentIdentity] = $this->studentFixture();
        $token = $this->login($studentIdentity, 'guidance-password');

        $this->withToken($token)->getJson('/api/v1/me/career-guidance')
            ->assertOk()
            ->assertJsonPath('data.schools.0.availability', 'guidance_unavailable')
            ->assertJsonMissing(['internal_id', 'student_number', 'guidance-admin@example.com']);

        $this->withToken($token)->postJson('/api/v1/me/career-guidance/suggestions', ['career_interest' => 'Medicine'])
            ->assertOk()
            ->assertJsonPath('data.status', 'unavailable')
            ->assertJsonPath('data.review_state', 'unreviewed')
            ->assertJsonPath('data.issue_codes.0', 'consent_required')
            ->assertJsonMissing(['admission_guaranteed', 'cut_off_mark', 'internal_id']);

        $this->assertDatabaseCount('students', 1);
    }

    public function test_non_student_identity_cannot_access_guidance(): void
    {
        $school = School::factory()->create();
        $identity = UserIdentity::factory()->withPassword('guidance-admin-password')->create();
        $identity->contacts()->update(['canonical_value' => 'guidance-admin@example.com', 'verified_at' => now()]);
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $identity->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', 'school_admin')->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);
        $token = $this->login($identity, 'guidance-admin-password');

        $this->withToken($token)->getJson('/api/v1/me/career-guidance')->assertNotFound();
    }

    /** @return array{0: School, 1: UserIdentity} */
    private function studentFixture(): array
    {
        $school = School::factory()->create();
        $identity = UserIdentity::factory()->withPassword('guidance-password')->create();
        $identity->contacts()->update(['canonical_value' => 'guidance-student@example.com', 'verified_at' => now()]);
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $identity->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', 'student')->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);
        TenantContext::runInternal(
            TenantContext::fromMembership($membership),
            'career guidance fixture',
            fn (): Student => Student::create(['school_id' => $school->id, 'user_id' => $identity->id, 'student_number' => 'GUIDANCE-001', 'display_name' => 'Fictional Guidance Student', 'admission_date' => '2025-09-01', 'status' => 'active']),
        );

        return [$school, $identity];
    }

    private function login(UserIdentity $identity, string $password): string
    {
        return $this->postJson('/api/v1/auth/login', ['login' => $identity->contacts()->firstOrFail()->canonical_value, 'password' => $password])->assertOk()->json('data.access_token');
    }
}
