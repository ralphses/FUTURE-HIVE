<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Contexts\Academic\Domain\Models\AcademicResultRun;
use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;

final class AcademicResultGateTest extends AcademicScoreSubmissionTest
{
    public function test_complete_result_run_can_be_approved_and_published_idempotently(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'result-gate-admin@example.com', 'result-gate-password');
        $token = $this->loginAndSelect($admin, $school, 'result-gate-password');
        $configuration = $this->configuration($school, $token);
        $run = AcademicResultRun::create([
            'school_id' => $school->id,
            'academic_session_id' => $configuration['session_id'],
            'academic_term_id' => $configuration['term_id'],
            'status' => 'completed',
            'source_fingerprint' => hash('sha256', 'fictional-result-gate'),
            'computed_count' => 1,
            'not_computable_count' => 0,
            'created_by' => $admin->id,
        ]);
        $publisher = UserIdentity::factory()->withPassword('result-gate-publisher-password')->create();
        $publisher->contacts()->update(['canonical_value' => 'result-gate-publisher@example.com', 'verified_at' => now()]);
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $publisher->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', 'principal')->value('id'), 'assigned_by' => $publisher->id, 'assigned_at' => now()]);
        $publisherToken = $this->loginAndSelect($publisher, $school, 'result-gate-publisher-password');
        $base = '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/result-runs/'.$run->public_id;

        $this->withToken($token)->getJson($base.'/gate')->assertOk()->assertJsonPath('data.gate.status', 'pending');
        $this->withToken($token)->postJson($base.'/approve')->assertOk()->assertJsonPath('data.gate.status', 'approved');
        $this->withToken($token)->postJson($base.'/approve')->assertOk()->assertJsonPath('data.gate.status', 'approved');
        $this->withToken($publisherToken)->postJson($base.'/publish')->assertOk()->assertJsonPath('data.gate.status', 'published');
        $this->withToken($publisherToken)->postJson($base.'/publish')->assertOk()->assertJsonPath('data.gate.status', 'published');

        $this->assertDatabaseHas('academic_result_gates', ['academic_result_run_id' => $run->id, 'status' => 'published']);
    }

    public function test_blocked_result_run_cannot_be_approved_and_rejection_requires_a_reason(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'result-gate-admin@example.com', 'result-gate-admin-password');
        $token = $this->loginAndSelect($admin, $school, 'result-gate-admin-password');
        $configuration = $this->configuration($school, $token);
        $run = AcademicResultRun::create([
            'school_id' => $school->id,
            'academic_session_id' => $configuration['session_id'],
            'academic_term_id' => $configuration['term_id'],
            'status' => 'blocked',
            'source_fingerprint' => hash('sha256', 'fictional-blocked-result-gate'),
            'computed_count' => 0,
            'not_computable_count' => 1,
            'created_by' => $admin->id,
        ]);
        $base = '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/result-runs/'.$run->public_id;

        $this->withToken($token)->postJson($base.'/approve')->assertUnprocessable();
        $this->withToken($token)->postJson($base.'/reject')->assertUnprocessable()->assertJsonPath('error.details.reason.0', 'The reason field is required.');
        $this->withToken($token)->postJson($base.'/reject', ['reason' => 'Fictional completeness issue'])->assertOk()->assertJsonPath('data.gate.status', 'rejected');
        $this->assertDatabaseHas('academic_result_gates', ['academic_result_run_id' => $run->id, 'status' => 'rejected', 'rejection_reason' => 'Fictional completeness issue']);
    }
}
