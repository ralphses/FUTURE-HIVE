<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Contexts\Finance\Domain\Models\FeeInvoice;
use App\Contexts\Finance\Domain\Models\FinancialLedgerBatch;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;
use Tests\Feature\Academic\AcademicScoreSubmissionTest;

final class FinancialLedgerTest extends AcademicScoreSubmissionTest
{
    public function test_finance_user_can_create_and_post_a_balanced_source_batch_idempotently(): void
    {
        [$school, $actor] = $this->schoolWithRole('school_admin', 'ledger-admin@example.com', 'ledger-password');
        $token = $this->loginAndSelect($actor, $school, 'ledger-password');
        $invoice = $this->issuedInvoice($school, $token);
        $path = '/api/v1/schools/'.$school->public_id.'/ledger';
        $payload = ['currency' => 'NGN', 'source_type' => 'invoice', 'source_public_id' => (string) $invoice->public_id, 'effective_date' => '2025-10-04', 'description' => 'Invoice posting', 'entries' => [['account_code' => 'receivable', 'direction' => 'debit', 'amount_minor_units' => 10000], ['account_code' => 'revenue', 'direction' => 'credit', 'amount_minor_units' => 10000]]];

        $batch = $this->withToken($token)->postJson($path, $payload)->assertCreated()->assertJsonPath('data.ledger_batch.status', 'pending')->json('data.ledger_batch.id');
        $this->withToken($token)->postJson($path, $payload)->assertCreated()->assertJsonPath('data.ledger_batch.id', $batch);
        $this->withToken($token)->postJson($path.'/'.$batch.'/post')->assertOk()->assertJsonPath('data.ledger_batch.status', 'posted');
        $this->withToken($token)->postJson($path.'/'.$batch.'/post')->assertOk()->assertJsonPath('data.ledger_batch.status', 'posted');
        self::assertSame(1, FinancialLedgerBatch::query()->count());
        self::assertSame(2, FinancialLedgerBatch::query()->firstOrFail()->entries()->count());
    }

    public function test_unbalanced_and_unissued_sources_are_rejected_without_persistence(): void
    {
        [$school, $actor] = $this->schoolWithRole('school_admin', 'ledger-validation@example.com', 'ledger-validation-password');
        $token = $this->loginAndSelect($actor, $school, 'ledger-validation-password');
        $configuration = $this->configuration($school, $token);
        $student = Student::create(['school_id' => $school->id, 'student_number' => 'LEDGER-DRAFT', 'display_name' => 'Fictional Ledger Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        StudentEnrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_level_id' => $configuration['level_id'], 'academic_section_id' => $configuration['section_id'], 'academic_class_arm_id' => $configuration['class_arm_id'], 'start_date' => '2025-09-01', 'status' => 'active']);
        $base = '/api/v1/schools/'.$school->public_id;
        $category = $this->withToken($token)->postJson($base.'/fee-categories', ['code' => 'LEDGER-DRAFT', 'name' => 'Ledger Draft'])->assertCreated()->json('data.category.id');
        $plan = $this->withToken($token)->postJson($base.'/fee-plans', ['session_id' => $configuration['session'], 'term_id' => $configuration['term'], 'name' => 'Ledger Draft Plan', 'currency' => 'NGN', 'effective_from' => '2025-09-01', 'effective_to' => '2025-12-15', 'lines' => [['category_id' => $category, 'amount_minor_units' => 10000, 'sequence' => 1, 'is_required' => true]]])->assertCreated()->json('data.plan.id');
        $this->withToken($token)->postJson($base.'/fee-plans/'.$plan.'/activate')->assertOk();
        $assessment = $this->withToken($token)->postJson($base.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/fee-assessments', ['plan_id' => $plan, 'due_date' => '2025-12-01'])->assertCreated()->json('data.assessment.id');
        $invoice = FeeInvoice::query()->firstOrFail();
        $path = $base.'/ledger';
        $unbalanced = ['currency' => 'NGN', 'source_type' => 'invoice', 'source_public_id' => (string) $invoice->public_id, 'effective_date' => '2025-10-04', 'entries' => [['account_code' => 'receivable', 'direction' => 'debit', 'amount_minor_units' => 10000], ['account_code' => 'revenue', 'direction' => 'credit', 'amount_minor_units' => 9000]]];
        $this->withToken($token)->postJson($path, $unbalanced)->assertUnprocessable();
        $this->withToken($token)->postJson($path, [...$unbalanced, 'entries' => [['account_code' => 'receivable', 'direction' => 'debit', 'amount_minor_units' => 10000], ['account_code' => 'revenue', 'direction' => 'credit', 'amount_minor_units' => 10000]]])->assertNotFound();
        self::assertSame(0, FinancialLedgerBatch::query()->count());
    }

    private function issuedInvoice(School $school, string $token): FeeInvoice
    {
        $configuration = $this->configuration($school, $token);
        $student = Student::create(['school_id' => $school->id, 'student_number' => 'LEDGER-'.fake()->unique()->numberBetween(100, 999), 'display_name' => 'Fictional Ledger Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        StudentEnrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_level_id' => $configuration['level_id'], 'academic_section_id' => $configuration['section_id'], 'academic_class_arm_id' => $configuration['class_arm_id'], 'start_date' => '2025-09-01', 'status' => 'active']);
        $base = '/api/v1/schools/'.$school->public_id;
        $category = $this->withToken($token)->postJson($base.'/fee-categories', ['code' => 'LEDGER-TUITION', 'name' => 'Ledger Tuition'])->assertCreated()->json('data.category.id');
        $plan = $this->withToken($token)->postJson($base.'/fee-plans', ['session_id' => $configuration['session'], 'term_id' => $configuration['term'], 'name' => 'Ledger Plan', 'currency' => 'NGN', 'effective_from' => '2025-09-01', 'effective_to' => '2025-12-15', 'lines' => [['category_id' => $category, 'amount_minor_units' => 10000, 'sequence' => 1, 'is_required' => true]]])->assertCreated()->json('data.plan.id');
        $this->withToken($token)->postJson($base.'/fee-plans/'.$plan.'/activate')->assertOk();
        $assessment = $this->withToken($token)->postJson($base.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/fee-assessments', ['plan_id' => $plan, 'due_date' => '2025-12-01'])->assertCreated()->json('data.assessment.id');
        $this->withToken($token)->postJson($base.'/fee-assessments/'.$assessment.'/finalize')->assertOk();

        return FeeInvoice::query()->firstOrFail();
    }
}
