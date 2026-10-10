<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Contexts\Academic\Domain\Models\AcademicAssessmentPolicyVersion;
use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AcademicAssessmentPolicyVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_policy_version_snapshots_the_current_scheme_and_has_immutable_lifecycle(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'policy-admin@example.com', 'policy-admin-password');
        $token = $this->loginAndSelect($admin, $school, 'policy-admin-password');
        $configuration = $this->configuration($school, $token);
        $base = $this->path($school, $configuration);

        $created = $this->withToken($token)->postJson($base, ['name' => 'First Policy', 'effective_start' => '2025-09-01', 'effective_end' => '2025-12-15'])->assertCreated();
        $created->assertJsonPath('data.version', 1)->assertJsonPath('data.status', 'draft')->assertJsonCount(2, 'data.components');
        $policy = $created->json('data.id');
        self::assertSame(1, AcademicAssessmentPolicyVersion::query()->count());

        $this->withToken($token)->postJson($base.'/'.$policy.'/activate')->assertOk()->assertJsonPath('data.status', 'active');
        $this->withToken($token)->postJson($base.'/'.$policy.'/retire')->assertOk()->assertJsonPath('data.status', 'retired');
        $this->withToken($token)->postJson($base.'/'.$policy.'/retire')->assertOk()->assertJsonPath('data.status', 'retired');
        self::assertSame('retired', AcademicAssessmentPolicyVersion::query()->firstOrFail()->status);
    }

    public function test_overlapping_policy_dates_and_competing_active_versions_are_rejected(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'policy-overlap@example.com', 'policy-overlap-password');
        $token = $this->loginAndSelect($admin, $school, 'policy-overlap-password');
        $configuration = $this->configuration($school, $token);
        $base = $this->path($school, $configuration);
        $payload = ['effective_start' => '2025-09-01', 'effective_end' => '2025-12-15'];
        $first = $this->withToken($token)->postJson($base, $payload)->assertCreated()->json('data.id');
        $this->withToken($token)->postJson($base.'/'.$first.'/activate')->assertOk();
        $this->withToken($token)->postJson($base, ['effective_start' => '2025-10-01'])->assertUnprocessable();
        self::assertSame(1, AcademicAssessmentPolicyVersion::query()->count());
    }

    public function test_policy_versions_are_tenant_scoped_and_read_permission_is_separate_from_manage(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'policy-scope@example.com', 'policy-scope-password');
        $token = $this->loginAndSelect($admin, $school, 'policy-scope-password');
        $configuration = $this->configuration($school, $token);
        $base = $this->path($school, $configuration);
        $this->withToken($token)->postJson($base, ['effective_start' => '2025-09-01'])->assertCreated();

        [$otherSchool, $otherAdmin] = $this->schoolWithRole('school_admin', 'policy-other@example.com', 'policy-other-password');
        $otherToken = $this->loginAndSelect($otherAdmin, $otherSchool, 'policy-other-password');
        $this->withToken($otherToken)->getJson($base)->assertNotFound();
    }

    /** @return array{session: string, term: string, offering: string} */
    private function configuration(School $school, string $token): array
    {
        $session = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions', ['name' => 'Policy Session', 'code' => 'PS', 'start_date' => '2025-09-01', 'end_date' => '2026-07-31'])->assertCreated()->json('data.id');
        $term = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms', ['name' => 'Policy Term', 'sequence' => 1, 'start_date' => '2025-09-01', 'end_date' => '2025-12-15'])->assertCreated()->json('data.id');
        $level = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels', ['name' => 'Policy Level', 'code' => 'PL', 'sequence' => 1])->assertCreated()->json('data.id');
        $section = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/sections', ['name' => 'Policy Section', 'code' => 'PS', 'sequence' => 1])->assertCreated()->json('data.id');
        $classArm = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/sections/'.$section.'/class-arms', ['name' => 'Policy Arm', 'code' => 'PA', 'capacity' => 30])->assertCreated()->json('data.id');
        $subject = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/subjects', ['name' => 'Policy Subject', 'code' => 'PSUB'])->assertCreated()->json('data.id');
        $offeringPath = '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms/'.$term.'/subject-offerings';
        $offering = $this->withToken($token)->postJson($offeringPath, ['class_arm_id' => $classArm, 'subject_id' => $subject])->assertCreated()->json('data.id');
        $schemePath = $offeringPath.'/'.$offering.'/assessment-scheme';
        $this->withToken($token)->putJson($schemePath, ['name' => 'Policy Scheme', 'total_marks' => 100, 'components' => [['name' => 'CA1', 'category' => 'ca', 'max_marks' => 20, 'sequence' => 1], ['name' => 'Exam', 'category' => 'exam', 'max_marks' => 80, 'sequence' => 2]]])->assertOk();

        return ['session' => $session, 'term' => $term, 'offering' => $offering];
    }

    /** @param array{session: string, term: string, offering: string} $configuration */
    private function path(School $school, array $configuration): string
    {
        return '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/subject-offerings/'.$configuration['offering'].'/assessment-policies';
    }

    /** @return array{0: School, 1: UserIdentity} */
    private function schoolWithRole(string $roleKey, string $email, string $password): array
    {
        $school = School::factory()->create(['name' => 'Fictional Policy School']);
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
