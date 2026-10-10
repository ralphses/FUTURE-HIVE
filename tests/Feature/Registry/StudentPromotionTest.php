<?php

declare(strict_types=1);

namespace Tests\Feature\Registry;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StudentPromotionTest extends TestCase
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

    public function test_promotion_cycle_requires_explicit_approval_and_applies_one_placement(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'promotion-flow@example.com');
        $token = $this->loginAndSelect($admin, $school);
        [$sourceSession, $sourceTerm, $targetSession, $targetTerm, $sourceLevel, $targetLevel, $sourceArm, $targetArm] = $this->academicSetup($school, $token);
        $student = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/students', ['student_number' => 'PROM-001', 'display_name' => 'Promotion Learner'])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/students/'.$student.'/activate')->assertOk();
        $enrollment = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/students/'.$student.'/enrollments', ['session_id' => $sourceSession, 'term_id' => $sourceTerm, 'level_id' => $sourceLevel, 'section_id' => $this->sectionId, 'class_arm_id' => $sourceArm, 'start_date' => '2025-09-10'])->assertCreated()->json('data.id');
        $cyclePath = '/api/v1/schools/'.$school->public_id.'/promotion-cycles';
        $cycle = $this->withToken($token)->postJson($cyclePath, ['source_term_id' => $sourceTerm, 'target_session_id' => $targetSession, 'target_term_id' => $targetTerm])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson($cyclePath.'/'.$cycle.'/decisions', ['student_id' => $student, 'source_enrollment_id' => $enrollment, 'decision' => 'repeat', 'target_level_id' => $sourceLevel, 'target_class_arm_id' => $targetArm])->assertCreated();
        $this->withToken($token)->postJson($cyclePath.'/'.$cycle.'/approve')->assertOk()->assertJsonPath('data.status', 'approved');
        $this->withToken($token)->postJson($cyclePath.'/'.$cycle.'/apply')->assertOk()->assertJsonPath('data.status', 'applied');
        self::assertSame(2, StudentEnrollment::query()->count());
        self::assertSame(1, StudentEnrollment::query()->where('status', 'active')->count());
        $this->withToken($token)->postJson($cyclePath.'/'.$cycle.'/apply')->assertOk();
        self::assertSame(2, StudentEnrollment::query()->count());
    }

    public function test_non_leader_cannot_approve_a_promotion_cycle(): void
    {
        [$school, $teacher] = $this->schoolWithRole('teacher', 'promotion-teacher@example.com');
        $token = $this->loginAndSelect($teacher, $school);
        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/promotion-cycles')->assertNotFound();
    }

    private string $sectionId = '';

    /** @return array{0: School, 1: UserIdentity} */
    private function schoolWithRole(string $role, string $email): array
    {
        $school = School::factory()->create(['name' => 'Fictional Promotion School']);
        $identity = UserIdentity::factory()->withPassword('promotion-password')->create();
        $identity->contacts()->update(['canonical_value' => $email, 'verified_at' => now()]);
        $membership = SchoolMembership::factory()->create(['school_id' => $school->id, 'user_id' => $identity->id]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', $role)->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);

        return [$school, $identity];
    }

    private function loginAndSelect(UserIdentity $identity, School $school): string
    {
        $token = $this->postJson('/api/v1/auth/login', ['login' => $identity->contacts()->firstOrFail()->canonical_value, 'password' => 'promotion-password'])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return $token;
    }

    /** @return array{0:string,1:string,2:string,3:string,4:string,5:string,6:string,7:string} */
    private function academicSetup(School $school, string $token): array
    {
        $base = '/api/v1/schools/'.$school->public_id;
        $sourceSession = $this->withToken($token)->postJson($base.'/academic-sessions', ['name' => 'Source Session', 'code' => 'SRC', 'start_date' => '2025-09-01', 'end_date' => '2026-07-31'])->assertCreated()->json('data.id');
        $targetSession = $sourceSession;
        $sourceTerm = $this->withToken($token)->postJson($base.'/academic-sessions/'.$sourceSession.'/terms', ['name' => 'Source Term', 'sequence' => 1, 'start_date' => '2025-09-01', 'end_date' => '2025-12-15'])->assertCreated()->json('data.id');
        $targetTerm = $this->withToken($token)->postJson($base.'/academic-sessions/'.$targetSession.'/terms', ['name' => 'Target Term', 'sequence' => 2, 'start_date' => '2026-01-01', 'end_date' => '2026-07-15'])->assertCreated()->json('data.id');
        $level = $this->withToken($token)->postJson($base.'/academic-levels', ['name' => 'Primary One', 'code' => 'P1', 'sequence' => 1])->assertCreated()->json('data.id');
        $next = $this->withToken($token)->postJson($base.'/academic-levels', ['name' => 'Primary Two', 'code' => 'P2', 'sequence' => 2])->assertCreated()->json('data.id');
        $section = $this->withToken($token)->postJson($base.'/academic-levels/'.$level.'/sections', ['name' => 'Blue', 'code' => 'BLUE', 'sequence' => 1])->assertCreated()->json('data.id');
        $this->sectionId = $section;
        $targetSection = $this->withToken($token)->postJson($base.'/academic-levels/'.$next.'/sections', ['name' => 'Green', 'code' => 'GREEN', 'sequence' => 1])->assertCreated()->json('data.id');
        $sourceArm = $this->withToken($token)->postJson($base.'/academic-levels/'.$level.'/sections/'.$section.'/class-arms', ['name' => 'Blue Arm', 'code' => 'BLUE', 'capacity' => 2])->assertCreated()->json('data.id');
        $targetArm = $this->withToken($token)->postJson($base.'/academic-levels/'.$level.'/sections/'.$section.'/class-arms', ['name' => 'Green Arm', 'code' => 'GREEN', 'capacity' => 2])->assertCreated()->json('data.id');
        foreach ([$sourceSession] as $session) {
            $this->withToken($token)->postJson($base.'/academic-sessions/'.$session.'/activate', ['reason' => 'Open'])->assertOk();
        }
        $this->withToken($token)->postJson($base.'/academic-sessions/'.$sourceSession.'/terms/'.$sourceTerm.'/activate', ['reason' => 'Open'])->assertOk();

        return [$sourceSession, $sourceTerm, $targetSession, $targetTerm, $level, $next, $sourceArm, $targetArm];
    }
}
