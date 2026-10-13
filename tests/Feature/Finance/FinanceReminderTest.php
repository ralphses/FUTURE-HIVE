<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Contexts\Finance\Domain\Models\FeeInvoice;
use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Domain\Models\GuardianProfile;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;
use App\Contexts\Registry\Domain\Models\StudentGuardianRelationship;
use Tests\Feature\Academic\AcademicScoreSubmissionTest;

final class FinanceReminderTest extends AcademicScoreSubmissionTest
{
    public function test_finance_staff_can_create_send_and_replay_a_fee_reminder_without_contact_data(): void
    {
        [$school, $actor] = $this->schoolWithRole('school_admin', 'reminder-admin@example.com', 'reminder-password');
        $token = $this->loginAndSelect($actor, $school, 'reminder-password');
        $configuration = $this->configuration($school, $token);
        $student = Student::create(['school_id' => $school->id, 'student_number' => 'REM-001', 'display_name' => 'Fictional Reminder Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);

        $this->createEnrollment($school, $student, $configuration);
        $guardian = $this->createGuardian($school, $student);
        $invoice = $this->createInvoice($school, $configuration, $token, $student);
        $base = '/api/v1/schools/'.$school->public_id;

        $created = $this->withToken($token)->postJson($base.'/fee-reminders', ['invoice_id' => $invoice, 'recipient_id' => $guardian->public_id, 'reminder_type' => 'outstanding_balance', 'period_key' => '2025-12'])->assertCreated()->assertJsonPath('data.reminder.status', 'draft');
        $reminder = $created->json('data.reminder.id');
        $this->withToken($token)->postJson($base.'/fee-reminders', ['invoice_id' => $invoice, 'recipient_id' => $guardian->public_id, 'reminder_type' => 'outstanding_balance', 'period_key' => '2025-12'])->assertCreated()->assertJsonPath('data.reminder.id', $reminder);
        $this->withToken($token)->postJson($base.'/fee-reminders/'.$reminder.'/send')->assertOk()->assertJsonPath('data.reminder.status', 'sent')->assertJsonMissing(['reminder-admin@example.com']);
        $this->withToken($token)->postJson($base.'/fee-reminders/'.$reminder.'/send')->assertOk()->assertJsonPath('data.reminder.status', 'sent');
    }

    public function test_draft_and_voided_invoice_reminders_are_not_available(): void
    {
        [$school, $actor] = $this->schoolWithRole('proprietor', 'reminder-blocked@example.com', 'reminder-blocked-password');
        $token = $this->loginAndSelect($actor, $school, 'reminder-blocked-password');
        $configuration = $this->configuration($school, $token);
        $student = Student::create(['school_id' => $school->id, 'student_number' => 'REM-002', 'display_name' => 'Fictional Blocked Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        $this->createEnrollment($school, $student, $configuration);
        $guardian = $this->createGuardian($school, $student);
        $invoice = $this->createInvoice($school, $configuration, $token, $student);
        $invoiceModel = FeeInvoice::query()->where('public_id', $invoice)->firstOrFail();
        $invoiceModel->update(['status' => 'draft']);

        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/fee-reminders', ['invoice_id' => $invoice, 'recipient_id' => $guardian->public_id, 'reminder_type' => 'outstanding_balance', 'period_key' => '2025-12'])->assertNotFound();
    }

    /** @param array<string, mixed> $configuration */
    private function createEnrollment(object $school, Student $student, array $configuration): void
    {
        StudentEnrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_level_id' => $configuration['level_id'], 'academic_section_id' => $configuration['section_id'], 'academic_class_arm_id' => $configuration['class_arm_id'], 'start_date' => '2025-09-01', 'status' => 'active']);
    }

    private function createGuardian(object $school, Student $student): UserIdentity
    {
        $guardian = UserIdentity::factory()->create();
        $guardian->contacts()->update(['verified_at' => now()]);
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $guardian->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', 'parent_guardian')->value('id'), 'assigned_by' => $guardian->id, 'assigned_at' => now()]);
        $profile = GuardianProfile::create(['user_id' => $guardian->id, 'display_name' => 'Fictional Reminder Guardian', 'metadata' => []]);
        StudentGuardianRelationship::create(['school_id' => $school->id, 'student_id' => $student->id, 'guardian_profile_id' => $profile->id, 'relationship_type' => 'parent', 'status' => 'active', 'verified_at' => now()]);

        return $guardian;
    }

    /** @param array<string, mixed> $configuration */
    private function createInvoice(object $school, array $configuration, string $token, Student $student): string
    {
        $category = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/fee-categories', ['code' => 'REM-'.$student->student_number, 'name' => 'Reminder Tuition'])->assertCreated()->json('data.category.id');
        $plan = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/fee-plans', ['session_id' => $configuration['session'], 'term_id' => $configuration['term'], 'name' => 'Reminder Plan', 'currency' => 'NGN', 'effective_from' => '2025-09-01', 'effective_to' => '2025-12-15', 'lines' => [['category_id' => $category, 'amount_minor_units' => 10000, 'sequence' => 1, 'is_required' => true]]])->assertCreated()->json('data.plan.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/fee-plans/'.$plan.'/activate')->assertOk();
        $assessment = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/fee-assessments', ['plan_id' => $plan, 'due_date' => '2025-12-01'])->assertCreated()->json('data.assessment.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/fee-assessments/'.$assessment.'/finalize')->assertOk();

        return (string) FeeInvoice::query()->where('student_id', $student->id)->value('public_id');
    }
}
