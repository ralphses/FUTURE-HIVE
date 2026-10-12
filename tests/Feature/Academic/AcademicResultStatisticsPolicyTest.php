<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

final class AcademicResultStatisticsPolicyTest extends AcademicScoreSubmissionTest
{
    public function test_leadership_can_create_activate_and_retire_a_statistics_policy(): void
    {
        [$school, $actor] = $this->schoolWithRole('proprietor', 'statistics-policy@example.com', 'statistics-policy-password');
        $token = $this->loginAndSelect($actor, $school, 'statistics-policy-password');
        $configuration = $this->configuration($school, $token);
        $base = '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/ranking-statistics-policies';
        $payload = ['included_scope' => 'school_term', 'passing_result_eligibility' => true, 'tie_handling' => 'dense', 'minimum_eligible_population' => 5, 'privacy_suppression_threshold' => 5];

        $created = $this->withToken($token)->postJson($base, $payload)->assertCreated()->assertJsonPath('data.policy.status', 'draft')->assertJsonPath('data.policy.version', 1);
        $policy = $created->json('data.policy.id');
        $this->withToken($token)->getJson($base)->assertOk()->assertJsonPath('data.policies.0.id', $policy);
        $this->withToken($token)->postJson($base.'/'.$policy.'/activate')->assertOk()->assertJsonPath('data.policy.status', 'active');
        $this->withToken($token)->postJson($base.'/'.$policy.'/activate')->assertOk()->assertJsonPath('data.policy.status', 'active');
        $this->withToken($token)->postJson($base.'/'.$policy.'/retire')->assertOk()->assertJsonPath('data.policy.status', 'retired');
        $this->withToken($token)->postJson($base.'/'.$policy.'/retire')->assertOk()->assertJsonPath('data.policy.status', 'retired');
    }

    public function test_only_one_active_statistics_policy_is_allowed_and_values_are_bounded(): void
    {
        [$school, $actor] = $this->schoolWithRole('proprietor', 'statistics-policy-unique@example.com', 'statistics-policy-unique-password');
        $token = $this->loginAndSelect($actor, $school, 'statistics-policy-unique-password');
        $configuration = $this->configuration($school, $token);
        $base = '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/ranking-statistics-policies';
        $payload = ['included_scope' => 'class_arm', 'passing_result_eligibility' => false, 'tie_handling' => 'competition', 'minimum_eligible_population' => 1, 'privacy_suppression_threshold' => 5];
        $first = $this->withToken($token)->postJson($base, $payload)->assertCreated()->json('data.policy.id');
        $this->withToken($token)->postJson($base.'/'.$first.'/activate')->assertOk();
        $second = $this->withToken($token)->postJson($base, $payload)->assertCreated()->json('data.policy.id');
        $this->withToken($token)->postJson($base.'/'.$second.'/activate')->assertUnprocessable();
        $this->withToken($token)->postJson($base, [...$payload, 'included_scope' => 'invalid'])->assertUnprocessable();
    }
}
