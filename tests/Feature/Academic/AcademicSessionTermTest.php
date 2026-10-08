<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Contexts\Academic\Domain\Models\AcademicSession;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AcademicSessionTermTest extends TestCase
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

    public function test_school_admin_can_create_activate_and_close_a_session_and_term(): void
    {
        [$school, $identity] = $this->schoolWithRole('school_admin', 'academic-admin-password');
        $token = $this->loginAndSelect($identity, $school, 'academic-admin-password');

        $session = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions', [
            'name' => '2025/2026 Academic Session',
            'code' => '2025-2026',
            'start_date' => '2025-09-01',
            'end_date' => '2026-07-31',
            'status' => 'active',
            'school_id' => 999999,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonMissingPath('data.school_id')
            ->json('data.id');

        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/activate', [
            'reason' => 'Academic calendar approved',
        ])->assertOk()->assertJsonPath('data.status', 'active');

        $term = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms', [
            'name' => 'First Term',
            'sequence' => 1,
            'start_date' => '2025-09-01',
            'end_date' => '2025-12-15',
            'status' => 'active',
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms/'.$term.'/activate', [
            'reason' => 'First term opened',
        ])->assertOk()->assertJsonPath('data.status', 'active');

        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/academic-context')
            ->assertOk()
            ->assertJsonPath('data.session.id', $session)
            ->assertJsonPath('data.term.id', $term);

        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms/'.$term.'/close', [
            'reason' => 'First term ended',
        ])->assertOk()->assertJsonPath('data.status', 'closed');

        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/close', [
            'reason' => 'Academic year ended',
        ])->assertOk()->assertJsonPath('data.status', 'closed');

        $this->assertDatabaseHas('academic_sessions', ['school_id' => $school->id, 'status' => 'closed']);
        $this->assertDatabaseHas('academic_terms', ['school_id' => $school->id, 'status' => 'closed']);
    }

    public function test_academic_periods_reject_overlaps_and_terms_outside_the_session(): void
    {
        [$school, $identity] = $this->schoolWithRole('school_admin', 'academic-overlap-password');
        $token = $this->loginAndSelect($identity, $school, 'academic-overlap-password');

        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions', [
            'name' => 'First Fictional Session', 'code' => 'FIRST', 'start_date' => '2025-09-01', 'end_date' => '2026-07-31',
        ])->assertCreated();

        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions', [
            'name' => 'Overlapping Fictional Session', 'code' => 'SECOND', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        ])->assertUnprocessable();

        $session = AcademicSession::query()->firstOrFail();
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session->public_id.'/terms', [
            'name' => 'Outside Term', 'sequence' => 1, 'start_date' => '2025-08-01', 'end_date' => '2025-12-15',
        ])->assertUnprocessable();
    }

    public function test_only_one_session_and_term_can_be_active_and_closed_periods_are_immutable(): void
    {
        [$school, $identity] = $this->schoolWithRole('school_admin', 'academic-state-password');
        $token = $this->loginAndSelect($identity, $school, 'academic-state-password');
        $sessionA = $this->createSession($token, $school, 'Session A', 'A', '2025-09-01', '2026-07-31');
        $sessionB = $this->createSession($token, $school, 'Session B', 'B', '2027-09-01', '2028-07-31');

        $this->activateSession($token, $school, $sessionA);
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$sessionB.'/activate', ['reason' => 'Second session'])->assertUnprocessable();

        $termA = $this->createTerm($token, $school, $sessionA, 'Term A', 1, '2025-09-01', '2025-12-15');
        $termB = $this->createTerm($token, $school, $sessionA, 'Term B', 2, '2026-01-05', '2026-04-15');
        $this->activateTerm($token, $school, $sessionA, $termA);
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$sessionA.'/terms/'.$termB.'/activate', ['reason' => 'Second term'])->assertUnprocessable();

        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$sessionA.'/terms/'.$termA.'/close', ['reason' => 'Term complete'])->assertOk();
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$sessionA.'/close', ['reason' => 'Session complete'])->assertOk();
        $this->withToken($token)->patchJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$sessionA, [
            'name' => 'Changed Closed Session', 'code' => 'CLOSED', 'start_date' => '2025-09-01', 'end_date' => '2026-07-31',
        ])->assertUnprocessable();

        self::assertSame(0, AcademicSession::query()->where('status', 'active')->count());
        self::assertSame(0, AcademicTerm::query()->where('status', 'active')->count());
    }

    public function test_read_permission_is_separate_from_management_and_cross_school_access_is_denied(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'academic-read-password');
        $sessionToken = $this->loginAndSelect($admin, $school, 'academic-read-password');
        $session = $this->createSession($sessionToken, $school, 'Readable Session', 'READ', '2025-09-01', '2026-07-31');

        [$otherSchool, $teacher] = $this->schoolWithRole('teacher', 'academic-teacher-password');
        $teacherToken = $this->loginAndSelect($teacher, $otherSchool, 'academic-teacher-password');
        $this->withToken($teacherToken)->getJson('/api/v1/schools/'.$otherSchool->public_id.'/academic-sessions')->assertOk();
        $this->withToken($teacherToken)->postJson('/api/v1/schools/'.$otherSchool->public_id.'/academic-sessions', [
            'name' => 'Not Allowed', 'code' => 'NO', 'start_date' => '2025-09-01', 'end_date' => '2026-07-31',
        ])->assertNotFound();
        $this->withToken($sessionToken)->getJson('/api/v1/schools/'.$otherSchool->public_id.'/academic-sessions')->assertNotFound();

        $this->withToken($sessionToken)->getJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session)->assertOk();
    }

    public function test_academic_routes_require_selected_context_and_jwt_claims_remain_identity_scoped(): void
    {
        [$school, $identity] = $this->schoolWithRole('school_admin', 'academic-context-password');
        $token = $this->login($identity, 'academic-context-password');

        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/academic-sessions')->assertForbidden();
        $this->assertArrayNotHasKey('school_id', $this->decodeTokenPayload($token));
        $this->assertArrayNotHasKey('academic_session_id', $this->decodeTokenPayload($token));
    }

    /** @return array{0: School, 1: UserIdentity} */
    private function schoolWithRole(string $roleKey, string $password): array
    {
        $school = School::factory()->create(['name' => 'Fictional Academic School']);
        $identity = UserIdentity::factory()->withPassword($password)->create();
        $membership = SchoolMembership::factory()->create(['school_id' => $school->id, 'user_id' => $identity->id]);
        MembershipRole::create([
            'school_membership_id' => $membership->id,
            'role_id' => Role::query()->where('key', $roleKey)->value('id'),
            'assigned_by' => $identity->id,
            'assigned_at' => now(),
        ]);

        return [$school, $identity];
    }

    private function login(UserIdentity $identity, string $password): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'login' => $identity->contacts()->firstOrFail()->canonical_value,
            'password' => $password,
        ])->assertOk()->json('data.access_token');
    }

    private function loginAndSelect(UserIdentity $identity, School $school, string $password): string
    {
        $token = $this->login($identity, $password);
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return $token;
    }

    private function createSession(string $token, School $school, string $name, string $code, string $start, string $end): string
    {
        return $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions', [
            'name' => $name, 'code' => $code, 'start_date' => $start, 'end_date' => $end,
        ])->assertCreated()->json('data.id');
    }

    private function activateSession(string $token, School $school, string $session): void
    {
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/activate', ['reason' => 'Session approved'])->assertOk();
    }

    private function createTerm(string $token, School $school, string $session, string $name, int $sequence, string $start, string $end): string
    {
        return $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms', [
            'name' => $name, 'sequence' => $sequence, 'start_date' => $start, 'end_date' => $end,
        ])->assertCreated()->json('data.id');
    }

    private function activateTerm(string $token, School $school, string $session, string $term): void
    {
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms/'.$term.'/activate', ['reason' => 'Term opened'])->assertOk();
    }

    /** @return array<string, mixed> */
    private function decodeTokenPayload(string $token): array
    {
        $payload = explode('.', $token)[1] ?? '';
        $decoded = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);

        return is_array($decoded) ? $decoded : [];
    }
}
