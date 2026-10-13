<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Contexts\Finance\Domain\Models\FeeCategory;
use App\Contexts\Finance\Domain\Models\FeePlanVersion;
use Tests\Feature\Academic\AcademicScoreSubmissionTest;

final class FeePlanTest extends AcademicScoreSubmissionTest
{
    public function test_leadership_can_create_update_activate_and_retire_fee_plan(): void
    {
        [$school, $actor] = $this->schoolWithRole('school_admin', 'fee-plan-admin@example.com', 'fee-plan-password');
        $token = $this->loginAndSelect($actor, $school, 'fee-plan-password');
        $configuration = $this->configuration($school, $token);
        $category = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/fee-categories', ['code' => 'TUITION', 'name' => 'Tuition', 'description' => 'Fictional tuition category'])->assertCreated()->json('data.category.id');
        $payload = [
            'session_id' => $configuration['session'],
            'term_id' => $configuration['term'],
            'name' => 'First Term Fictional Plan',
            'currency' => 'NGN',
            'effective_from' => '2025-09-01',
            'effective_to' => '2025-12-15',
            'lines' => [['category_id' => $category, 'amount_minor_units' => 18000000, 'sequence' => 1, 'is_required' => true]],
        ];
        $base = '/api/v1/schools/'.$school->public_id.'/fee-plans';

        $created = $this->withToken($token)->postJson($base, $payload)->assertCreated()->assertJsonPath('data.plan.status', 'draft')->assertJsonPath('data.plan.lines.0.amount_minor_units', 18000000);
        $plan = $created->json('data.plan.id');
        $this->withToken($token)->patchJson($base.'/'.$plan, ['name' => 'Updated Fictional Plan'])->assertOk()->assertJsonPath('data.plan.name', 'Updated Fictional Plan');
        $this->withToken($token)->postJson($base.'/'.$plan.'/activate')->assertOk()->assertJsonPath('data.plan.status', 'active');
        $this->withToken($token)->postJson($base.'/'.$plan.'/activate')->assertOk()->assertJsonPath('data.plan.status', 'active');
        $this->withToken($token)->patchJson($base.'/'.$plan, ['name' => 'Must Not Change'])->assertUnprocessable();
        $this->withToken($token)->postJson($base.'/'.$plan.'/retire')->assertOk()->assertJsonPath('data.plan.status', 'retired');
        $this->withToken($token)->postJson($base.'/'.$plan.'/retire')->assertOk()->assertJsonPath('data.plan.status', 'retired');

        self::assertSame(1, FeeCategory::query()->count());
        self::assertSame(1, FeePlanVersion::query()->count());
        $this->withToken($token)->getJson($base)->assertOk()->assertJsonPath('data.plans.0.status', 'retired');
    }

    public function test_only_one_active_fee_plan_is_allowed_and_dates_are_term_contained(): void
    {
        [$school, $actor] = $this->schoolWithRole('proprietor', 'fee-plan-unique@example.com', 'fee-plan-unique-password');
        $token = $this->loginAndSelect($actor, $school, 'fee-plan-unique-password');
        $configuration = $this->configuration($school, $token);
        $category = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/fee-categories', ['code' => 'BOOKS', 'name' => 'Books'])->assertCreated()->json('data.category.id');
        $base = '/api/v1/schools/'.$school->public_id.'/fee-plans';
        $payload = ['session_id' => $configuration['session'], 'term_id' => $configuration['term'], 'name' => 'Plan', 'currency' => 'NGN', 'effective_from' => '2025-09-01', 'effective_to' => '2025-12-15', 'lines' => [['category_id' => $category, 'amount_minor_units' => 100, 'sequence' => 1, 'is_required' => false]]];
        $first = $this->withToken($token)->postJson($base, $payload)->assertCreated()->json('data.plan.id');
        $this->withToken($token)->postJson($base.'/'.$first.'/activate')->assertOk();
        $second = $this->withToken($token)->postJson($base, $payload)->assertCreated()->json('data.plan.id');
        $this->withToken($token)->postJson($base.'/'.$second.'/activate')->assertUnprocessable();
        $this->withToken($token)->postJson($base, [...$payload, 'effective_from' => '2025-08-01'])->assertUnprocessable();
    }

    public function test_cross_school_and_unprivileged_requests_are_denied_generically(): void
    {
        [$school, $actor] = $this->schoolWithRole('teacher', 'fee-plan-teacher@example.com', 'fee-plan-teacher-password');
        $token = $this->loginAndSelect($actor, $school, 'fee-plan-teacher-password');
        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/fee-plans')->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/schools/00000000-0000-7000-8000-000000000000/fee-plans')->assertNotFound();
    }
}
