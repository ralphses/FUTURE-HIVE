<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Contexts\Academic\Domain\Models\AcademicAssessmentInstance;
use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AcademicAssessmentInstanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_and_moves_an_assessment_instance_through_its_lifecycle(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'instance-admin@example.com', 'instance-password');
        $token = $this->loginAndSelect($admin, $school, 'instance-password');
        $configuration = $this->configuration($school, $token);
        $base = $this->path($school, $configuration);
        $payload = ['policy_id' => $configuration['policy'], 'grading_scale_id' => $configuration['scale'], 'title' => 'First fictional assessment', 'assessment_date' => '2025-10-10', 'instructions' => 'Use the fictional assessment guide.'];

        $instance = $this->withToken($token)->postJson($base, $payload)->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
        $this->withToken($token)->patchJson($base.'/'.$instance, ['title' => 'Updated fictional assessment', 'assessment_date' => '2025-10-11', 'instructions' => null])->assertOk()->assertJsonPath('data.title', 'Updated fictional assessment');
        $this->withToken($token)->postJson($base.'/'.$instance.'/open')->assertOk()->assertJsonPath('data.status', 'open');
        $this->withToken($token)->postJson($base.'/'.$instance.'/open')->assertOk()->assertJsonPath('data.status', 'open');
        $this->withToken($token)->postJson($base.'/'.$instance.'/close')->assertOk()->assertJsonPath('data.status', 'closed');
        $this->withToken($token)->patchJson($base.'/'.$instance, ['title' => 'Not editable', 'assessment_date' => '2025-10-12'])->assertUnprocessable();

        $this->assertDatabaseHas('academic_assessment_instances', ['public_id' => $instance, 'status' => 'closed']);
        self::assertSame(1, AcademicAssessmentInstance::query()->count());
    }

    public function test_rejects_duplicate_instances_and_dates_outside_the_term(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'instance-validation@example.com', 'instance-validation-password');
        $token = $this->loginAndSelect($admin, $school, 'instance-validation-password');
        $configuration = $this->configuration($school, $token);
        $base = $this->path($school, $configuration);
        $payload = ['policy_id' => $configuration['policy'], 'grading_scale_id' => $configuration['scale'], 'title' => 'Duplicate fictional assessment', 'assessment_date' => '2025-10-10'];

        $this->withToken($token)->postJson($base, $payload)->assertCreated();
        $this->withToken($token)->postJson($base, $payload)->assertUnprocessable();
        $this->withToken($token)->postJson($base, [...$payload, 'title' => 'Outside fictional assessment', 'assessment_date' => '2026-01-01'])->assertUnprocessable();
    }

    /** @return array{session: string, term: string, offering: string, policy: string, scale: string} */
    private function configuration(School $school, string $token): array
    {
        $session = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions', ['name' => 'Instance Session', 'code' => 'AIS', 'start_date' => '2025-09-01', 'end_date' => '2026-07-31'])->assertCreated()->json('data.id');
        $term = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms', ['name' => 'Instance Term', 'sequence' => 1, 'start_date' => '2025-09-01', 'end_date' => '2025-12-15'])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/activate', ['reason' => 'Fictional setup activation'])->assertOk();
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms/'.$term.'/activate', ['reason' => 'Fictional setup activation'])->assertOk();
        $level = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels', ['name' => 'Instance Level', 'code' => 'AIL', 'sequence' => 1])->assertCreated()->json('data.id');
        $section = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/sections', ['name' => 'Instance Section', 'code' => 'AIS', 'sequence' => 1])->assertCreated()->json('data.id');
        $classArm = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/sections/'.$section.'/class-arms', ['name' => 'Instance Arm', 'code' => 'AIA', 'capacity' => 30])->assertCreated()->json('data.id');
        $subject = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/subjects', ['name' => 'Instance Subject', 'code' => 'AISUB'])->assertCreated()->json('data.id');
        $offeringBase = '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms/'.$term.'/subject-offerings';
        $offering = $this->withToken($token)->postJson($offeringBase, ['class_arm_id' => $classArm, 'subject_id' => $subject])->assertCreated()->json('data.id');
        $schemeBase = $offeringBase.'/'.$offering.'/assessment-scheme';
        $this->withToken($token)->putJson($schemeBase, ['name' => 'Instance Scheme', 'total_marks' => 100, 'components' => [['name' => 'CA', 'category' => 'ca', 'max_marks' => 20, 'sequence' => 1], ['name' => 'Exam', 'category' => 'exam', 'max_marks' => 80, 'sequence' => 2]]])->assertOk();
        $policyBase = $offeringBase.'/'.$offering.'/assessment-policies';
        $policy = $this->withToken($token)->postJson($policyBase, ['effective_start' => '2025-09-01'])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson($policyBase.'/'.$policy.'/activate')->assertOk();
        $scaleBase = $policyBase.'/'.$policy.'/grading-scales';
        $scale = $this->withToken($token)->postJson($scaleBase, ['name' => 'Instance Scale', 'effective_start' => '2025-09-01', 'bands' => [['grade' => 'F', 'label' => 'Fail', 'minimum_percentage' => 0, 'maximum_percentage' => 49.99, 'is_passing' => false, 'sequence' => 1], ['grade' => 'P', 'label' => 'Pass', 'minimum_percentage' => 50, 'maximum_percentage' => 100, 'is_passing' => true, 'sequence' => 2]]])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson($scaleBase.'/'.$scale.'/activate')->assertOk();

        return ['session' => $session, 'term' => $term, 'offering' => $offering, 'policy' => $policy, 'scale' => $scale];
    }

    /** @param array{session: string, term: string, offering: string, policy: string, scale: string} $configuration */
    private function path(School $school, array $configuration): string
    {
        return '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/subject-offerings/'.$configuration['offering'].'/assessment-instances';
    }

    /** @return array{0: School, 1: UserIdentity} */
    private function schoolWithRole(string $roleKey, string $email, string $password): array
    {
        $school = School::factory()->create(['name' => 'Fictional Instance School']);
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
