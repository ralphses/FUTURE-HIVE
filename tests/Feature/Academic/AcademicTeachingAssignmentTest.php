<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Contexts\Academic\Domain\Models\AcademicTeachingAssignment;
use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Domain\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AcademicTeachingAssignmentTest extends TestCase
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

    public function test_leadership_can_assign_an_active_teacher_to_an_offering(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'assignment-admin@example.com', 'assignment-admin-password');
        $teacher = $this->teacherInSchool($school, 'assignment-teacher@example.com');
        $token = $this->loginAndSelect($admin, $school, 'assignment-admin-password');
        $configuration = $this->configuration($school, $token);

        $response = $this->withToken($token)->postJson($this->path($school, $configuration['session'], $configuration['term'], $configuration['offering']).'/teaching-assignments', [
            'teacher_id' => $teacher->public_id,
            'effective_start' => '2025-09-01',
            'effective_end' => '2025-10-31',
            'reason' => 'Fictional staffing plan',
            'school_id' => Str::uuid7()->toString(),
            'status' => 'revoked',
            'membership_id' => 999,
        ])->assertCreated()->assertJsonPath('data.teacher_id', (string) $teacher->public_id)->assertJsonPath('data.status', 'active');

        $assignment = AcademicTeachingAssignment::query()->firstOrFail();
        self::assertSame('active', $assignment->status);
        self::assertSame(1, AuditEvent::query()->where('subject_type', 'academic_teaching_assignment')->count());
        self::assertTrue(Str::isUuid((string) $assignment->public_id));
    }

    public function test_non_leadership_and_non_teacher_identities_cannot_administer_assignments(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'assignment-auth-admin@example.com', 'assignment-auth-admin-password');
        $teacher = $this->teacherInSchool($school, 'assignment-auth-teacher@example.com');
        $member = $this->memberInSchool($school, 'assignment-auth-member@example.com');
        $token = $this->loginAndSelect($admin, $school, 'assignment-auth-admin-password');
        $configuration = $this->configuration($school, $token);
        $path = $this->path($school, $configuration['session'], $configuration['term'], $configuration['offering']).'/teaching-assignments';
        $payload = ['teacher_id' => $teacher->public_id, 'effective_start' => '2025-09-01'];

        $teacherToken = $this->loginAndSelect($teacher, $school, 'assignment-auth-teacher-password');
        $this->withToken($teacherToken)->postJson($path, $payload)->assertNotFound();
        $memberToken = $this->loginAndSelect($member, $school, 'assignment-auth-member-password');
        $this->withToken($memberToken)->postJson($path, $payload)->assertNotFound();
    }

    public function test_assignments_require_teacher_role_and_term_contained_non_overlapping_dates(): void
    {
        [$school, $admin] = $this->schoolWithRole('principal', 'assignment-date-admin@example.com', 'assignment-date-admin-password');
        $teacher = $this->teacherInSchool($school, 'assignment-date-teacher@example.com');
        $token = $this->loginAndSelect($admin, $school, 'assignment-date-admin-password');
        $configuration = $this->configuration($school, $token);
        $path = $this->path($school, $configuration['session'], $configuration['term'], $configuration['offering']).'/teaching-assignments';

        $this->withToken($token)->postJson($path, ['teacher_id' => $teacher->public_id, 'effective_start' => '2025-09-01', 'effective_end' => '2025-10-31'])->assertCreated();
        $this->withToken($token)->postJson($path, ['teacher_id' => $teacher->public_id, 'effective_start' => '2025-10-01', 'effective_end' => '2025-11-01'])->assertUnprocessable();
        $this->withToken($token)->postJson($path, ['teacher_id' => $teacher->public_id, 'effective_start' => '2026-01-01'])->assertUnprocessable();

        $nonTeacher = $this->memberInSchool($school, 'assignment-date-member@example.com');
        $this->withToken($token)->postJson($path, ['teacher_id' => $nonTeacher->public_id, 'effective_start' => '2025-11-01'])->assertNotFound();
    }

    public function test_teacher_reads_only_their_own_assignments(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'assignment-read-admin@example.com', 'assignment-read-admin-password');
        $teacher = $this->teacherInSchool($school, 'assignment-read-teacher@example.com');
        $otherTeacher = $this->teacherInSchool($school, 'assignment-read-other@example.com');
        $token = $this->loginAndSelect($admin, $school, 'assignment-read-admin-password');
        $configuration = $this->configuration($school, $token);
        $path = $this->path($school, $configuration['session'], $configuration['term'], $configuration['offering']).'/teaching-assignments';
        $this->withToken($token)->postJson($path, ['teacher_id' => $teacher->public_id, 'effective_start' => '2025-09-01'])->assertCreated();

        $teacherToken = $this->loginAndSelect($teacher, $school, 'assignment-read-teacher-password');
        $this->withToken($teacherToken)->getJson($path)->assertOk()->assertJsonCount(1, 'data.items');
        $otherToken = $this->loginAndSelect($otherTeacher, $school, 'assignment-read-other-password');
        $this->withToken($otherToken)->getJson($path)->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function test_revocation_is_idempotent_and_inactive_or_closed_offerings_reject_assignments(): void
    {
        [$school, $admin] = $this->schoolWithRole('proprietor', 'assignment-state-admin@example.com', 'assignment-state-admin-password');
        $teacher = $this->teacherInSchool($school, 'assignment-state-teacher@example.com');
        $token = $this->loginAndSelect($admin, $school, 'assignment-state-admin-password');
        $configuration = $this->configuration($school, $token);
        $basePath = $this->path($school, $configuration['session'], $configuration['term'], $configuration['offering']);
        $assignment = $this->withToken($token)->postJson($basePath.'/teaching-assignments', ['teacher_id' => $teacher->public_id, 'effective_start' => '2025-09-01'])->assertCreated()->json('data.id');
        $revokePath = $basePath.'/teaching-assignments/'.$assignment.'/revoke';
        $this->withToken($token)->postJson($revokePath, ['reason' => 'Fictional staffing change'])->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->withToken($token)->postJson($revokePath, ['reason' => 'Repeated fictional staffing change'])->assertOk()->assertJsonPath('data.status', 'revoked');

        $offeringPath = '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/subject-offerings/'.$configuration['offering'];
        $this->withToken($token)->postJson($offeringPath.'/deactivate')->assertOk();
        $this->withToken($token)->postJson($basePath.'/teaching-assignments', ['teacher_id' => $teacher->public_id, 'effective_start' => '2025-09-01'])->assertUnprocessable();
        self::assertFalse(Schema::hasTable('students'));
        self::assertFalse(Schema::hasTable('assessments'));
    }

    /** @return array{session: string, term: string, offering: string} */
    private function configuration(School $school, string $token): array
    {
        $bootstrap = UserIdentity::factory()->withPassword('assignment-bootstrap-password')->create();
        $bootstrap->contacts()->update(['canonical_value' => 'assignment-bootstrap@example.com', 'verified_at' => now()]);
        $this->assignRole($school, $bootstrap, 'school_admin');
        $configurationToken = $this->loginAndSelect($bootstrap, $school, 'assignment-bootstrap-password');
        $session = $this->withToken($configurationToken)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions', ['name' => 'Fictional Assignment Session', 'code' => 'FAS', 'start_date' => '2025-09-01', 'end_date' => '2026-07-31'])->assertCreated()->json('data.id');
        $term = $this->withToken($configurationToken)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms', ['name' => 'Assignment Term', 'sequence' => 1, 'start_date' => '2025-09-01', 'end_date' => '2025-12-15'])->assertCreated()->json('data.id');
        $this->withToken($configurationToken)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/activate', ['reason' => 'Fictional session activation'])->assertOk();
        $this->withToken($configurationToken)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms/'.$term.'/activate', ['reason' => 'Fictional term activation'])->assertOk();
        $level = $this->withToken($configurationToken)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels', ['name' => 'Assignment Level', 'code' => 'AL', 'sequence' => 1])->assertCreated()->json('data.id');
        $section = $this->withToken($configurationToken)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/sections', ['name' => 'Assignment Section', 'code' => 'AS', 'sequence' => 1])->assertCreated()->json('data.id');
        $classArm = $this->withToken($configurationToken)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/sections/'.$section.'/class-arms', ['name' => 'Assignment Arm', 'code' => 'AA', 'capacity' => 30])->assertCreated()->json('data.id');
        $subject = $this->withToken($configurationToken)->postJson('/api/v1/schools/'.$school->public_id.'/subjects', ['name' => 'Assignment Subject', 'code' => 'ASUB'])->assertCreated()->json('data.id');
        $offering = $this->withToken($configurationToken)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms/'.$term.'/subject-offerings', ['class_arm_id' => $classArm, 'subject_id' => $subject])->assertCreated()->json('data.id');

        return ['session' => $session, 'term' => $term, 'offering' => $offering];
    }

    private function path(School $school, string $session, string $term, string $offering): string
    {
        return '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms/'.$term.'/subject-offerings/'.$offering;
    }

    /** @return array{0: School, 1: UserIdentity} */
    private function schoolWithRole(string $roleKey, string $email, string $password): array
    {
        $school = School::factory()->create(['name' => 'Fictional Assignment School']);
        $identity = UserIdentity::factory()->withPassword($password)->create();
        $identity->contacts()->update(['canonical_value' => $email, 'verified_at' => now()]);
        $this->assignRole($school, $identity, $roleKey);

        return [$school, $identity];
    }

    private function teacherInSchool(School $school, string $email): UserIdentity
    {
        $identity = UserIdentity::factory()->withPassword(str_replace('@example.com', '-password', $email))->create();
        $identity->contacts()->update(['canonical_value' => $email, 'verified_at' => now()]);
        $this->assignRole($school, $identity, 'teacher');

        return $identity;
    }

    private function memberInSchool(School $school, string $email): UserIdentity
    {
        $identity = UserIdentity::factory()->withPassword(str_replace('@example.com', '-password', $email))->create();
        $identity->contacts()->update(['canonical_value' => $email, 'verified_at' => now()]);
        $this->assignRole($school, $identity, null);

        return $identity;
    }

    private function assignRole(School $school, UserIdentity $identity, ?string $roleKey): void
    {
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $identity->id, 'status' => 'active', 'joined_at' => now()]);
        if ($roleKey !== null) {
            MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', $roleKey)->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);
        }
    }

    private function loginAndSelect(UserIdentity $identity, School $school, string $password): string
    {
        $token = $this->postJson('/api/v1/auth/login', ['login' => $identity->contacts()->firstOrFail()->canonical_value, 'password' => $password])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return $token;
    }
}
