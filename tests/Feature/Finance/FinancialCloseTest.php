<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Contexts\Finance\Domain\Models\FinanceReconciliationItem;
use App\Contexts\Finance\Domain\Models\FinanceReconciliationRun;
use Tests\Feature\Academic\AcademicScoreSubmissionTest;

final class FinancialCloseTest extends AcademicScoreSubmissionTest
{
    public function test_financial_period_can_close_reopen_and_repeat_safe_transitions(): void
    {
        [$school, $actor] = $this->schoolWithRole('school_admin', 'close-admin@example.com', 'close-password');
        $token = $this->loginAndSelect($actor, $school, 'close-password');
        $configuration = $this->configuration($school, $token);
        $base = '/api/v1/schools/'.$school->public_id;
        $period = $base.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/financial-period';

        $this->withToken($token)->getJson($period)->assertOk()->assertJsonPath('data.period.status', 'open');
        $this->withToken($token)->postJson($period.'/close/start')->assertOk()->assertJsonPath('data.period.status', 'closing');
        $this->withToken($token)->postJson($period.'/close/start')->assertOk()->assertJsonPath('data.period.status', 'closing');
        $this->withToken($token)->postJson($period.'/close/complete')->assertOk()->assertJsonPath('data.period.status', 'closed');
        $this->withToken($token)->postJson($period.'/close/complete')->assertOk()->assertJsonPath('data.period.status', 'closed');
        $this->withToken($token)->postJson($period.'/reopen', ['reason' => 'Approved close review'])->assertOk()->assertJsonPath('data.period.status', 'reopened');
        $this->withToken($token)->postJson($period.'/reopen', ['reason' => 'Approved close review'])->assertOk()->assertJsonPath('data.period.status', 'reopened');
    }

    public function test_unresolved_reconciliation_exception_blocks_close(): void
    {
        [$school, $actor] = $this->schoolWithRole('proprietor', 'close-blocked@example.com', 'close-blocked-password');
        $token = $this->loginAndSelect($actor, $school, 'close-blocked-password');
        $configuration = $this->configuration($school, $token);
        $run = FinanceReconciliationRun::query()->create([
            'school_id' => $school->id,
            'source_fingerprint' => hash('sha256', 'close-blocked-run'),
            'status' => 'completed_with_exceptions',
            'created_by' => $actor->id,
        ]);
        FinanceReconciliationItem::query()->create([
            'finance_reconciliation_run_id' => $run->id,
            'school_id' => $school->id,
            'source_type' => 'invoice',
            'source_public_id' => '00000000-0000-7000-8000-000000000001',
            'amount_minor_units' => 100,
            'currency' => 'NGN',
            'status' => 'exception',
            'exception_code' => 'UNMATCHED_SOURCE',
        ]);

        $period = '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/financial-period';
        $this->withToken($token)->postJson($period.'/close/start')->assertUnprocessable();
    }

    public function test_cross_school_period_requests_are_denied_generically(): void
    {
        [$school, $actor] = $this->schoolWithRole('school_admin', 'close-cross-school@example.com', 'close-cross-school-password');
        $token = $this->loginAndSelect($actor, $school, 'close-cross-school-password');
        $configuration = $this->configuration($school, $token);
        $period = '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/financial-period';

        $this->withToken($token)->getJson($period)->assertOk();
        $this->withToken($token)->getJson('/api/v1/schools/00000000-0000-7000-8000-000000000000/financial-periods')->assertNotFound();
    }
}
