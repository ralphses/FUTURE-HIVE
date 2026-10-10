<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Contexts\Academic\Domain\Models\AcademicSubjectOffering;
use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Domain\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AcademicSubjectOfferingTest extends TestCase
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

    public function test_admin_can_create_and_update_a_subject_offering(): void
    {
        [$school, $identity] = $this->schoolWithRole('school_admin', 'offering-admin@example.com', 'offering-admin-password');
        $token = $this->loginAndSelect($identity, $school, 'offering-admin-password');
        $configuration = $this->configuration($school, $token);
        $path = $this->path($school, $configuration['session'], $configuration['term']);

        $offering = $this->withToken($token)->postJson($path, [
            'class_arm_id' => $configuration['class_arm'],
            'subject_id' => $configuration['subject'],
            'display_order' => 2,
            'status' => 'inactive',
            'school_id' => 999,
            'academic_term_id' => 999,
        ])->assertCreated()->assertJsonPath('data.status', 'active')->assertJsonPath('data.class_arm_id', $configuration['class_arm'])->assertJsonPath('data.subject_id', $configuration['subject'])->json('data.id');

        $this->withToken($token)->patchJson($path.'/'.$offering, ['display_order' => 3, 'status' => 'inactive', 'actor_id' => 999])->assertOk()->assertJsonPath('data.display_order', 3);

        self::assertSame(1, AcademicSubjectOffering::query()->where('school_id', $school->id)->count());
        self::assertSame(2, AuditEvent::query()->where('subject_type', 'academic_subject_offering')->count());
    }

    public function test_offering_uniqueness_is_scoped_to_term_class_arm_and_subject(): void
    {
        [$school, $identity] = $this->schoolWithRole('school_admin', 'offering-duplicate@example.com', 'offering-duplicate-password');
        $token = $this->loginAndSelect($identity, $school, 'offering-duplicate-password');
        $configuration = $this->configuration($school, $token);
        $path = $this->path($school, $configuration['session'], $configuration['term']);
        $payload = ['class_arm_id' => $configuration['class_arm'], 'subject_id' => $configuration['subject']];
        $this->withToken($token)->postJson($path, $payload)->assertCreated();
        $this->withToken($token)->postJson($path, $payload)->assertUnprocessable();

        $secondClassArm = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$configuration['level'].'/sections/'.$configuration['section'].'/class-arms', ['name' => 'Red Arm', 'code' => 'RED', 'capacity' => 30])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson($path, ['class_arm_id' => $secondClassArm, 'subject_id' => $configuration['subject']])->assertCreated();
    }

    public function test_cross_school_context_and_read_manage_permissions_are_enforced(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'offering-cross@example.com', 'offering-cross-password');
        $token = $this->loginAndSelect($admin, $school, 'offering-cross-password');
        $configuration = $this->configuration($school, $token);
        $path = $this->path($school, $configuration['session'], $configuration['term']);
        $offering = $this->withToken($token)->postJson($path, ['class_arm_id' => $configuration['class_arm'], 'subject_id' => $configuration['subject']])->assertCreated()->json('data.id');

        [$otherSchool, $teacher] = $this->schoolWithRole('teacher', 'offering-teacher@example.com', 'offering-teacher-password');
        $teacherToken = $this->loginAndSelect($teacher, $otherSchool, 'offering-teacher-password');
        $this->withToken($teacherToken)->getJson($path)->assertNotFound();
        $this->withToken($teacherToken)->getJson($this->path($otherSchool, $configuration['session'], $configuration['term'].'/'.$offering))->assertNotFound();
        $this->withToken($token)->getJson($path.'/'.$offering)->assertOk();
        $this->withToken($teacherToken)->postJson($this->path($otherSchool, $configuration['session'], $configuration['term']), ['class_arm_id' => $configuration['class_arm'], 'subject_id' => $configuration['subject']])->assertNotFound();
    }

    public function test_inactive_structures_and_closed_terms_reject_new_or_changed_offerings(): void
    {
        [$school, $identity] = $this->schoolWithRole('school_admin', 'offering-state@example.com', 'offering-state-password');
        $token = $this->loginAndSelect($identity, $school, 'offering-state-password');
        $configuration = $this->configuration($school, $token);
        $subjectPath = '/api/v1/schools/'.$school->public_id.'/subjects/'.$configuration['subject'].'/deactivate';
        $this->withToken($token)->postJson($subjectPath)->assertOk();
        $this->withToken($token)->postJson($this->path($school, $configuration['session'], $configuration['term']), ['class_arm_id' => $configuration['class_arm'], 'subject_id' => $configuration['subject']])->assertUnprocessable();

        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/subjects/'.$configuration['subject'].'/activate')->assertOk();
        $termPath = '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'];
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/activate', ['reason' => 'Fictional session opening'])->assertOk();
        $this->withToken($token)->postJson($termPath.'/activate', ['reason' => 'Fictional term opening'])->assertOk();
        $this->withToken($token)->postJson($termPath.'/close', ['reason' => 'Fictional term ended'])->assertOk();
        $this->withToken($token)->postJson($this->path($school, $configuration['session'], $configuration['term']), ['class_arm_id' => $configuration['class_arm'], 'subject_id' => $configuration['subject']])->assertUnprocessable();
    }

    public function test_activation_and_deactivation_are_idempotent_and_do_not_create_domain_records(): void
    {
        [$school, $identity] = $this->schoolWithRole('school_admin', 'offering-lifecycle@example.com', 'offering-lifecycle-password');
        $token = $this->loginAndSelect($identity, $school, 'offering-lifecycle-password');
        $configuration = $this->configuration($school, $token);
        $path = $this->path($school, $configuration['session'], $configuration['term']);
        $offering = $this->withToken($token)->postJson($path, ['class_arm_id' => $configuration['class_arm'], 'subject_id' => $configuration['subject']])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson($path.'/'.$offering.'/deactivate')->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->withToken($token)->postJson($path.'/'.$offering.'/deactivate')->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->withToken($token)->postJson($path.'/'.$offering.'/activate')->assertOk()->assertJsonPath('data.status', 'active');

        self::assertSame(0, Schema::getConnection()->table('students')->count());
        self::assertFalse(Schema::hasTable('enrolments'));
        self::assertFalse(Schema::hasTable('assessments'));
    }

    /** @return array{session: string, term: string, level: string, section: string, class_arm: string, subject: string} */
    private function configuration(School $school, string $token): array
    {
        $session = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions', ['name' => 'Fictional Session', 'code' => 'FIC', 'start_date' => '2025-09-01', 'end_date' => '2026-07-31'])->assertCreated()->json('data.id');
        $term = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms', ['name' => 'First Term', 'sequence' => 1, 'start_date' => '2025-09-01', 'end_date' => '2025-12-15'])->assertCreated()->json('data.id');
        $level = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels', ['name' => 'Primary 1', 'code' => 'PRI1', 'sequence' => 1])->assertCreated()->json('data.id');
        $section = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/sections', ['name' => 'Blue', 'code' => 'BLUE', 'sequence' => 1])->assertCreated()->json('data.id');
        $classArm = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/sections/'.$section.'/class-arms', ['name' => 'Blue Arm', 'code' => 'BLUE-A', 'capacity' => 30])->assertCreated()->json('data.id');
        $subject = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/subjects', ['name' => 'Fictional Mathematics', 'code' => 'FM'])->assertCreated()->json('data.id');

        return ['session' => $session, 'term' => $term, 'level' => $level, 'section' => $section, 'class_arm' => $classArm, 'subject' => $subject];
    }

    private function path(School $school, string $session, string $term, ?string $suffix = null): string
    {
        return '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms/'.$term.'/subject-offerings'.($suffix === null ? '' : '/'.$suffix);
    }

    /** @return array{0: School, 1: UserIdentity} */
    private function schoolWithRole(string $roleKey, string $email, string $password): array
    {
        $school = School::factory()->create(['name' => 'Fictional Offering School']);
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
