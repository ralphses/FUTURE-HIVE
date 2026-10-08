<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Contexts\Academic\Domain\Models\AcademicSubject;
use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Domain\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AcademicSubjectTest extends TestCase
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

    public function test_admin_can_create_update_and_transition_a_subject(): void
    {
        [$school, $identity] = $this->schoolWithRole('school_admin', 'subject-admin@example.com', 'subject-admin-password');
        $token = $this->loginAndSelect($identity, $school, 'subject-admin-password');
        $path = '/api/v1/schools/'.$school->public_id.'/subjects';

        $subject = $this->withToken($token)->postJson($path, [
            'name' => 'Fictional Studies',
            'code' => 'FST',
            'classification' => 'elective',
            'status' => 'inactive',
            'school_id' => 999,
            'created_at' => '2000-01-01T00:00:00Z',
        ])->assertCreated()->assertJsonPath('data.status', 'active')->json('data.id');

        $this->withToken($token)->patchJson($path.'/'.$subject, [
            'name' => 'Fictional Studies Updated',
            'code' => 'FST-2',
            'classification' => 'core',
            'status' => 'inactive',
            'school_id' => 999,
        ])->assertOk()->assertJsonPath('data.status', 'active');
        $this->withToken($token)->postJson($path.'/'.$subject.'/deactivate')->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->withToken($token)->postJson($path.'/'.$subject.'/deactivate')->assertOk()->assertJsonPath('data.status', 'inactive');

        self::assertSame(1, AcademicSubject::query()->where('school_id', $school->id)->count());
        self::assertSame(3, AuditEvent::query()->where('subject_type', 'academic_subject')->count());
    }

    public function test_subject_names_and_codes_are_unique_per_school_only(): void
    {
        [$school, $identity] = $this->schoolWithRole('school_admin', 'subject-duplicate@example.com', 'subject-duplicate-password');
        $token = $this->loginAndSelect($identity, $school, 'subject-duplicate-password');
        $path = '/api/v1/schools/'.$school->public_id.'/subjects';
        $this->withToken($token)->postJson($path, ['name' => 'Fictional Mathematics', 'code' => 'FM'])->assertCreated();
        $this->withToken($token)->postJson($path, ['name' => 'Fictional Mathematics', 'code' => 'OTHER'])->assertUnprocessable();
        $this->withToken($token)->postJson($path, ['name' => 'Other Subject', 'code' => 'FM'])->assertUnprocessable();

        [$otherSchool, $otherIdentity] = $this->schoolWithRole('school_admin', 'subject-other@example.com', 'subject-other-password');
        $otherToken = $this->loginAndSelect($otherIdentity, $otherSchool, 'subject-other-password');
        $this->withToken($otherToken)->postJson('/api/v1/schools/'.$otherSchool->public_id.'/subjects', ['name' => 'Fictional Mathematics', 'code' => 'FM'])->assertCreated();
    }

    public function test_read_permission_does_not_grant_subject_management(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'subject-read-admin@example.com', 'subject-read-admin-password');
        $adminToken = $this->loginAndSelect($admin, $school, 'subject-read-admin-password');
        $this->withToken($adminToken)->postJson('/api/v1/schools/'.$school->public_id.'/subjects', ['name' => 'Fictional History', 'code' => 'FH'])->assertCreated();

        [$memberSchool, $member] = $this->schoolWithRole('teacher', 'subject-teacher@example.com', 'subject-teacher-password');
        $memberToken = $this->loginAndSelect($member, $memberSchool, 'subject-teacher-password');
        $this->withToken($memberToken)->getJson('/api/v1/schools/'.$memberSchool->public_id.'/subjects')->assertOk();
        $this->withToken($memberToken)->postJson('/api/v1/schools/'.$memberSchool->public_id.'/subjects', ['name' => 'Blocked Subject'])->assertNotFound();
    }

    public function test_cross_school_subjects_and_invalid_context_are_hidden(): void
    {
        [$school, $identity] = $this->schoolWithRole('school_admin', 'subject-cross@example.com', 'subject-cross-password');
        [$otherSchool, $otherIdentity] = $this->schoolWithRole('school_admin', 'subject-cross-other@example.com', 'subject-cross-other-password');
        $token = $this->loginAndSelect($identity, $school, 'subject-cross-password');
        $otherToken = $this->loginAndSelect($otherIdentity, $otherSchool, 'subject-cross-other-password');
        $subject = $this->withToken($otherToken)->postJson('/api/v1/schools/'.$otherSchool->public_id.'/subjects', ['name' => 'Private Subject'])->assertCreated()->json('data.id');

        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/subjects/'.$subject)->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/schools/'.$otherSchool->public_id.'/subjects')->assertNotFound();
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $otherSchool->public_id])->assertNotFound();
    }

    public function test_inactive_subjects_are_retained_and_reactivated_without_new_records(): void
    {
        [$school, $identity] = $this->schoolWithRole('school_admin', 'subject-state@example.com', 'subject-state-password');
        $token = $this->loginAndSelect($identity, $school, 'subject-state-password');
        $path = '/api/v1/schools/'.$school->public_id.'/subjects';
        $subject = $this->withToken($token)->postJson($path, ['name' => 'Fictional Science', 'code' => 'FS'])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson($path.'/'.$subject.'/deactivate')->assertOk();
        $this->withToken($token)->postJson($path.'/'.$subject.'/activate')->assertOk()->assertJsonPath('data.status', 'active');

        self::assertSame(1, AcademicSubject::query()->where('school_id', $school->id)->count());
    }

    /** @return array{0: School, 1: UserIdentity} */
    private function schoolWithRole(string $roleKey, string $email, string $password): array
    {
        $school = School::factory()->create(['name' => 'Fictional Subject Academy']);
        $identity = UserIdentity::factory()->withPassword($password)->create();
        $identity->contacts()->update(['canonical_value' => $email, 'verified_at' => now()]);
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $identity->id, 'status' => 'active', 'is_owner' => $roleKey === 'school_admin', 'joined_at' => now()]);
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
