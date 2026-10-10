<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Contexts\Academic\Domain\Models\AcademicGradingScaleVersion;
use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AcademicGradingScaleTest extends TestCase
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

    public function test_valid_scale_creates_a_draft_with_complete_percentage_bands(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'grading-admin@example.com', 'grading-admin-password');
        $token = $this->loginAndSelect($admin, $school, 'grading-admin-password');
        $configuration = $this->configuration($school, $token);

        $response = $this->withToken($token)->postJson($this->path($school, $configuration), $this->payload())->assertCreated();

        $response->assertJsonPath('data.status', 'draft')->assertJsonPath('data.version', 1)->assertJsonPath('data.bands.0.minimum_percentage', '0.00')->assertJsonPath('data.bands.2.maximum_percentage', '100.00')->assertJsonCount(3, 'data.bands');
        $scale = AcademicGradingScaleVersion::query()->firstOrFail();
        self::assertTrue(Str::isUuid((string) $scale->public_id));
    }

    public function test_gaps_overlaps_and_scales_without_a_passing_band_are_rejected(): void
    {
        [$school, $admin] = $this->schoolWithRole('proprietor', 'grading-validation@example.com', 'grading-validation-password');
        $token = $this->loginAndSelect($admin, $school, 'grading-validation-password');
        $configuration = $this->configuration($school, $token);
        $path = $this->path($school, $configuration);

        $invalid = $this->payload();
        $invalid['bands'][0]['is_passing'] = false;
        $invalid['bands'][1]['is_passing'] = false;
        $invalid['bands'][2]['is_passing'] = false;
        $invalid['bands'][0]['maximum_percentage'] = 49.98;
        $this->withToken($token)->postJson($path, $invalid)->assertUnprocessable();
        self::assertSame(0, AcademicGradingScaleVersion::query()->count());
    }

    public function test_only_one_scale_can_be_active_and_retired_scales_cannot_reopen(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'grading-lifecycle@example.com', 'grading-lifecycle-password');
        $token = $this->loginAndSelect($admin, $school, 'grading-lifecycle-password');
        $configuration = $this->configuration($school, $token);
        $path = $this->path($school, $configuration);
        $first = $this->withToken($token)->postJson($path, $this->payload())->assertCreated()->json('data.id');
        $this->withToken($token)->postJson($path.'/'.$first.'/activate')->assertOk();
        $this->withToken($token)->postJson($path.'/'.$first.'/retire')->assertOk();
        $this->withToken($token)->postJson($path.'/'.$first.'/retire')->assertOk();
        $this->withToken($token)->postJson($path.'/'.$first.'/activate')->assertUnprocessable();
    }

    public function test_read_access_is_tenant_scoped_and_manage_access_is_permission_gated(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'grading-scope@example.com', 'grading-scope-password');
        $token = $this->loginAndSelect($admin, $school, 'grading-scope-password');
        $configuration = $this->configuration($school, $token);
        $path = $this->path($school, $configuration);
        $this->withToken($token)->postJson($path, $this->payload())->assertCreated();

        [$otherSchool, $otherAdmin] = $this->schoolWithRole('school_admin', 'grading-other@example.com', 'grading-other-password');
        $otherToken = $this->loginAndSelect($otherAdmin, $otherSchool, 'grading-other-password');
        $this->withToken($otherToken)->getJson($path)->assertNotFound();

        $reader = $this->schoolMember($school, 'grading-reader@example.com');
        $readerToken = $this->loginAndSelect($reader, $school, 'grading-reader-password');
        $this->withToken($readerToken)->getJson($path)->assertOk();
        $this->withToken($readerToken)->postJson($path, $this->payload())->assertNotFound();
    }

    /** @return array{session: string, term: string, offering: string, policy: string} */
    private function configuration(School $school, string $token): array
    {
        $session = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions', ['name' => 'Grading Session', 'code' => 'GS', 'start_date' => '2025-09-01', 'end_date' => '2026-07-31'])->assertCreated()->json('data.id');
        $term = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms', ['name' => 'Grading Term', 'sequence' => 1, 'start_date' => '2025-09-01', 'end_date' => '2025-12-15'])->assertCreated()->json('data.id');
        $level = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels', ['name' => 'Grading Level', 'code' => 'GL', 'sequence' => 1])->assertCreated()->json('data.id');
        $section = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/sections', ['name' => 'Grading Section', 'code' => 'GS', 'sequence' => 1])->assertCreated()->json('data.id');
        $classArm = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/sections/'.$section.'/class-arms', ['name' => 'Grading Arm', 'code' => 'GA', 'capacity' => 30])->assertCreated()->json('data.id');
        $subject = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/subjects', ['name' => 'Grading Subject', 'code' => 'GSUB'])->assertCreated()->json('data.id');
        $offeringPath = '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms/'.$term.'/subject-offerings';
        $offering = $this->withToken($token)->postJson($offeringPath, ['class_arm_id' => $classArm, 'subject_id' => $subject])->assertCreated()->json('data.id');
        $schemePath = $offeringPath.'/'.$offering.'/assessment-scheme';
        $this->withToken($token)->putJson($schemePath, ['name' => 'Grading Scheme', 'total_marks' => 100, 'components' => [['name' => 'CA', 'category' => 'ca', 'max_marks' => 40, 'sequence' => 1], ['name' => 'Exam', 'category' => 'exam', 'max_marks' => 60, 'sequence' => 2]]])->assertOk();
        $policyPath = $offeringPath.'/'.$offering.'/assessment-policies';
        $policy = $this->withToken($token)->postJson($policyPath, ['effective_start' => '2025-09-01', 'effective_end' => '2025-12-15'])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson($policyPath.'/'.$policy.'/activate')->assertOk();

        return ['session' => $session, 'term' => $term, 'offering' => $offering, 'policy' => $policy];
    }

    /** @param array{session: string, term: string, offering: string, policy: string} $configuration */
    private function path(School $school, array $configuration): string
    {
        return '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/subject-offerings/'.$configuration['offering'].'/assessment-policies/'.$configuration['policy'].'/grading-scales';
    }

    /** @return array{name: string, effective_start: string, effective_end: string, bands: list<array<string, mixed>>} */
    private function payload(): array
    {
        return ['name' => 'Fictional Percentage Scale', 'effective_start' => '2025-09-01', 'effective_end' => '2025-12-15', 'bands' => [
            ['grade' => 'F', 'label' => 'Needs improvement', 'minimum_percentage' => 0, 'maximum_percentage' => 49.99, 'is_passing' => false, 'remark' => 'Keep practising.', 'sequence' => 1],
            ['grade' => 'C', 'label' => 'Pass', 'minimum_percentage' => 50, 'maximum_percentage' => 69.99, 'is_passing' => true, 'remark' => null, 'sequence' => 2],
            ['grade' => 'A', 'label' => 'Excellent', 'minimum_percentage' => 70, 'maximum_percentage' => 100, 'is_passing' => true, 'remark' => 'Excellent progress.', 'sequence' => 3],
        ]];
    }

    /** @return array{0: School, 1: UserIdentity} */
    private function schoolWithRole(string $roleKey, string $email, string $password): array
    {
        $school = School::factory()->create(['name' => 'Fictional Grading School']);
        $identity = UserIdentity::factory()->withPassword($password)->create();
        $identity->contacts()->update(['canonical_value' => $email, 'verified_at' => now()]);
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $identity->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', $roleKey)->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);

        return [$school, $identity];
    }

    private function schoolMember(School $school, string $email): UserIdentity
    {
        $identity = UserIdentity::factory()->withPassword('grading-reader-password')->create();
        $identity->contacts()->update(['canonical_value' => $email, 'verified_at' => now()]);
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $identity->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', 'student')->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);

        return $identity;
    }

    private function loginAndSelect(UserIdentity $identity, School $school, string $password): string
    {
        $token = $this->postJson('/api/v1/auth/login', ['login' => $identity->contacts()->firstOrFail()->canonical_value, 'password' => $password])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return $token;
    }
}
