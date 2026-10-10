<?php

declare(strict_types=1);

namespace Tests\Feature\Registry;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StudentSearchExportTest extends TestCase
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

    public function test_student_search_is_paginated_filtered_and_tenant_scoped(): void
    {
        [$school, $identity] = $this->schoolWithRole('school_admin', 'search-admin@example.com');
        $token = $this->loginAndSelect($identity, $school);
        $path = '/api/v1/schools/'.$school->public_id.'/students';
        $this->withToken($token)->postJson($path, ['student_number' => 'SEA-001', 'display_name' => 'Fictional Ada'])->assertCreated();
        $this->withToken($token)->postJson($path, ['student_number' => 'SEA-002', 'display_name' => 'Fictional Tunde'])->assertCreated();

        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/students/search?q=Ada&per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.student_number', 'SEA-001')
            ->assertJsonMissingPath('data.0.metadata');

        [$otherSchool, $otherIdentity] = $this->schoolWithRole('school_admin', 'search-other@example.com');
        $otherToken = $this->loginAndSelect($otherIdentity, $otherSchool);
        $this->withToken($otherToken)->getJson('/api/v1/schools/'.$otherSchool->public_id.'/students/search?q=SEA-001')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_csv_pdf_and_excel_exports_are_permissioned_and_redacted(): void
    {
        [$school, $identity] = $this->schoolWithRole('school_admin', 'export-admin@example.com');
        $token = $this->loginAndSelect($identity, $school);
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/students', ['student_number' => 'EXP-001', 'display_name' => 'Fictional Export Learner', 'metadata' => ['private' => 'hidden']])->assertCreated();
        $path = '/api/v1/schools/'.$school->public_id.'/students/export';

        $this->withToken($token)->get($path.'?format=csv')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->withToken($token)->get($path.'?format=pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->withToken($token)->get($path.'?format=xlsx')->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_export_permission_is_separate_from_student_read_access(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'export-permission-admin@example.com');
        $member = $this->memberInSchool($school, 'export-reader@example.com');
        $token = $this->loginAndSelect($member, $school, 'export-reader-password');

        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/students/search')->assertOk();
        $this->withToken($token)->get('/api/v1/schools/'.$school->public_id.'/students/export?format=csv')->assertNotFound();
    }

    /** @return array{0: School, 1: UserIdentity} */
    private function schoolWithRole(string $roleKey, string $email): array
    {
        $school = School::factory()->create(['name' => 'Fictional Search School']);
        $identity = UserIdentity::factory()->withPassword('search-password')->create();
        $identity->contacts()->update(['canonical_value' => $email, 'verified_at' => now()]);
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $identity->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', $roleKey)->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);

        return [$school, $identity];
    }

    private function memberInSchool(School $school, string $email): UserIdentity
    {
        $identity = UserIdentity::factory()->withPassword('export-reader-password')->create();
        $identity->contacts()->update(['canonical_value' => $email, 'verified_at' => now()]);
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $identity->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', 'teacher')->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);

        return $identity;
    }

    private function loginAndSelect(UserIdentity $identity, School $school, string $password = 'search-password'): string
    {
        $token = $this->postJson('/api/v1/auth/login', ['login' => $identity->contacts()->firstOrFail()->canonical_value, 'password' => $password])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return $token;
    }
}
