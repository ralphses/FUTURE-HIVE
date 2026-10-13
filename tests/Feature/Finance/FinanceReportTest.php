<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;
use Tests\Feature\Academic\AcademicScoreSubmissionTest;

final class FinanceReportTest extends AcademicScoreSubmissionTest
{
    public function test_finance_reports_are_derived_and_exportable(): void
    {
        [$school, $actor] = $this->schoolWithRole('school_admin', 'reports-admin@example.com', 'reports-password');
        $token = $this->loginAndSelect($actor, $school, 'reports-password');
        $configuration = $this->configuration($school, $token);
        $student = Student::create(['school_id' => $school->id, 'student_number' => 'REPORT-001', 'display_name' => 'Fictional Report Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        StudentEnrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_level_id' => $configuration['level_id'], 'academic_section_id' => $configuration['section_id'], 'academic_class_arm_id' => $configuration['class_arm_id'], 'start_date' => '2025-09-01', 'status' => 'active']);
        $category = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/fee-categories', ['code' => 'REPORT-TUITION', 'name' => 'Report Tuition'])->assertCreated()->json('data.category.id');
        $plan = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/fee-plans', ['session_id' => $configuration['session'], 'term_id' => $configuration['term'], 'name' => 'Report Plan', 'currency' => 'NGN', 'effective_from' => '2025-09-01', 'effective_to' => '2025-12-15', 'lines' => [['category_id' => $category, 'amount_minor_units' => 12500, 'sequence' => 1, 'is_required' => true]]])->assertCreated()->json('data.plan.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/fee-plans/'.$plan.'/activate')->assertOk();
        $assessment = '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/fee-assessments';
        $assessmentId = $this->withToken($token)->postJson($assessment, ['plan_id' => $plan, 'due_date' => '2025-12-01'])->assertCreated()->json('data.assessment.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/fee-assessments/'.$assessmentId.'/finalize')->assertOk();

        $base = '/api/v1/schools/'.$school->public_id.'/finance-reports';
        $this->withToken($token)->getJson($base.'/collections')->assertOk()->assertJsonPath('data.rows.0.currency', 'NGN')->assertJsonPath('data.rows.0.invoiced_minor_units', 12500);
        $this->withToken($token)->getJson($base.'/arrears')->assertOk()->assertJsonStructure(['data' => ['rows' => [['invoice_id', 'invoice_number', 'currency', 'outstanding_minor_units', 'due_date', 'aging_bucket', 'issues']]]]);
        $this->withToken($token)->getJson($base.'/fee-plans')->assertOk()->assertJsonPath('data.rows.0.category_code', 'REPORT-TUITION');
        $this->withToken($token)->get($base.'/export?format=csv')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_report_period_is_bounded(): void
    {
        [$school, $actor] = $this->schoolWithRole('school_admin', 'reports-bound-admin@example.com', 'reports-bound-password');
        $token = $this->loginAndSelect($actor, $school, 'reports-bound-password');

        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/finance-reports/collections?from=2024-01-01&to=2025-01-02')->assertUnprocessable();
    }
}
