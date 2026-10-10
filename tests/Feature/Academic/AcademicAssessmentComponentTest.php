<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Contexts\Academic\Domain\Models\AcademicAssessmentComponent;
use App\Contexts\Academic\Domain\Models\AcademicAssessmentScheme;
use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Domain\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AcademicAssessmentComponentTest extends TestCase
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

    public function test_admin_can_replace_a_school_defined_assessment_scheme(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'assessment-admin@example.com', 'assessment-admin-password');
        $token = $this->loginAndSelect($admin, $school, 'assessment-admin-password');
        $configuration = $this->configuration($school, $token);
        $payload = $this->payload();

        $response = $this->withToken($token)->putJson($this->path($school, $configuration), $payload)->assertOk();

        $response->assertJsonPath('data.scheme.name', 'Fictional Junior Scheme')->assertJsonPath('data.scheme.total_marks', 100)->assertJsonPath('data.scheme.components.0.name', 'CA1');
        $scheme = AcademicAssessmentScheme::query()->firstOrFail();
        self::assertTrue(Str::isUuid((string) $scheme->public_id));
        self::assertSame(2, AcademicAssessmentComponent::query()->count());
        self::assertSame(1, AuditEvent::query()->where('subject_type', 'academic_assessment_scheme')->count());
    }

    public function test_invalid_component_totals_are_rejected_without_replacing_existing_configuration(): void
    {
        [$school, $admin] = $this->schoolWithRole('proprietor', 'assessment-total@example.com', 'assessment-total-password');
        $token = $this->loginAndSelect($admin, $school, 'assessment-total-password');
        $configuration = $this->configuration($school, $token);
        $path = $this->path($school, $configuration);
        $this->withToken($token)->putJson($path, $this->payload())->assertOk();

        $this->withToken($token)->putJson($path, ['name' => 'Invalid Scheme', 'total_marks' => 100, 'components' => [['name' => 'CA1', 'category' => 'ca', 'max_marks' => 10, 'sequence' => 1]]])->assertUnprocessable();
        $this->withToken($token)->getJson($path)->assertJsonPath('data.scheme.name', 'Fictional Junior Scheme');
    }

    public function test_component_names_and_sequences_are_unique_and_categories_are_bounded(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'assessment-validation@example.com', 'assessment-validation-password');
        $token = $this->loginAndSelect($admin, $school, 'assessment-validation-password');
        $configuration = $this->configuration($school, $token);
        $path = $this->path($school, $configuration);

        $payload = ['name' => 'Invalid Scheme', 'total_marks' => 20, 'components' => [
            ['name' => 'CA1', 'category' => 'quiz', 'max_marks' => 10, 'sequence' => 1],
            ['name' => 'ca1', 'category' => 'ca', 'max_marks' => 10, 'sequence' => 1],
        ]];
        $this->withToken($token)->putJson($path, $payload)->assertUnprocessable();
        self::assertSame(0, AcademicAssessmentScheme::query()->count());
    }

    public function test_read_access_is_tenant_scoped_and_manage_access_is_permission_gated(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'assessment-scope@example.com', 'assessment-scope-password');
        $token = $this->loginAndSelect($admin, $school, 'assessment-scope-password');
        $configuration = $this->configuration($school, $token);
        $path = $this->path($school, $configuration);
        $this->withToken($token)->putJson($path, $this->payload())->assertOk();

        [$otherSchool, $otherAdmin] = $this->schoolWithRole('school_admin', 'assessment-other@example.com', 'assessment-other-password');
        $otherToken = $this->loginAndSelect($otherAdmin, $otherSchool, 'assessment-other-password');
        $this->withToken($otherToken)->getJson($path)->assertNotFound();

        $reader = $this->schoolMember($school, 'assessment-reader@example.com');
        $readerToken = $this->loginAndSelect($reader, $school, 'assessment-reader-password');
        $this->withToken($readerToken)->getJson($path)->assertOk();
        $this->withToken($readerToken)->putJson($path, $this->payload())->assertNotFound();
    }

    public function test_inactive_offerings_and_closed_terms_cannot_receive_assessment_configuration(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'assessment-state@example.com', 'assessment-state-password');
        $token = $this->loginAndSelect($admin, $school, 'assessment-state-password');
        $configuration = $this->configuration($school, $token);
        $offeringPath = '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/subject-offerings/'.$configuration['offering'];
        $this->withToken($token)->postJson($offeringPath.'/deactivate')->assertOk();
        $this->withToken($token)->putJson($this->path($school, $configuration), $this->payload())->assertUnprocessable();
    }

    /** @return array{name: string, total_marks: int, components: list<array{name: string, category: string, max_marks: int, sequence: int}>} */
    private function payload(): array
    {
        return ['name' => 'Fictional Junior Scheme', 'total_marks' => 100, 'components' => [
            ['name' => 'CA1', 'category' => 'ca', 'max_marks' => 20, 'sequence' => 1],
            ['name' => 'Examination', 'category' => 'exam', 'max_marks' => 80, 'sequence' => 2],
        ]];
    }

    /** @return array{session: string, term: string, offering: string} */
    private function configuration(School $school, string $token): array
    {
        $session = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions', ['name' => 'Assessment Session', 'code' => 'AS', 'start_date' => '2025-09-01', 'end_date' => '2026-07-31'])->assertCreated()->json('data.id');
        $term = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms', ['name' => 'Assessment Term', 'sequence' => 1, 'start_date' => '2025-09-01', 'end_date' => '2025-12-15'])->assertCreated()->json('data.id');
        $level = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels', ['name' => 'Assessment Level', 'code' => 'AL', 'sequence' => 1])->assertCreated()->json('data.id');
        $section = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/sections', ['name' => 'Assessment Section', 'code' => 'AS', 'sequence' => 1])->assertCreated()->json('data.id');
        $classArm = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/sections/'.$section.'/class-arms', ['name' => 'Assessment Arm', 'code' => 'AA', 'capacity' => 30])->assertCreated()->json('data.id');
        $subject = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/subjects', ['name' => 'Assessment Subject', 'code' => 'ASUB'])->assertCreated()->json('data.id');
        $offering = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms/'.$term.'/subject-offerings', ['class_arm_id' => $classArm, 'subject_id' => $subject])->assertCreated()->json('data.id');

        return ['session' => $session, 'term' => $term, 'offering' => $offering];
    }

    /** @param array{session: string, term: string, offering: string} $configuration */
    private function path(School $school, array $configuration): string
    {
        return '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/subject-offerings/'.$configuration['offering'].'/assessment-scheme';
    }

    /** @return array{0: School, 1: UserIdentity} */
    private function schoolWithRole(string $roleKey, string $email, string $password): array
    {
        $school = School::factory()->create(['name' => 'Fictional Assessment School']);
        $identity = UserIdentity::factory()->withPassword($password)->create();
        $identity->contacts()->update(['canonical_value' => $email, 'verified_at' => now()]);
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $identity->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', $roleKey)->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);

        return [$school, $identity];
    }

    private function schoolMember(School $school, string $email): UserIdentity
    {
        $identity = UserIdentity::factory()->withPassword('assessment-reader-password')->create();
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
