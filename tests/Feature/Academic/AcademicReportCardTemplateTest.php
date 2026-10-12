<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Contexts\Academic\Domain\Models\AcademicResultGate;
use App\Contexts\Academic\Domain\Models\AcademicResultRun;
use App\Contexts\Academic\Domain\Models\AcademicResultSnapshot;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\UserIdentity;

final class AcademicReportCardTemplateTest extends AcademicScoreSubmissionTest
{
    public function test_leadership_can_version_activate_and_retire_a_report_card_template(): void
    {
        [$school, $publisher] = $this->schoolWithRole('proprietor', 'template-publisher@example.com', 'template-password');
        $token = $this->loginAndSelect($publisher, $school, 'template-password');
        $configuration = $this->configuration($school, $token);
        $snapshot = $this->publishedSnapshot($school, $publisher, $configuration);
        $base = '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/report-card-templates';
        $payload = $this->templatePayload((string) $snapshot->public_id);

        $created = $this->withToken($token)->postJson($base, $payload)->assertCreated()->assertJsonPath('data.template.status', 'draft')->assertJsonPath('data.template.version', 1);
        $template = $created->json('data.template.id');
        self::assertSame('Student identity', $created->json('data.template.sections.0.label'));
        self::assertSame('student_name', $created->json('data.template.sections.0.fields.0.key'));

        $this->withToken($token)->postJson($base.'/'.$template.'/activate')->assertOk()->assertJsonPath('data.template.status', 'active');
        $this->withToken($token)->postJson($base.'/'.$template.'/activate')->assertOk()->assertJsonPath('data.template.status', 'active');
        $this->withToken($token)->patchJson($base.'/'.$template, $payload)->assertUnprocessable();
        $this->withToken($token)->postJson($base.'/'.$template.'/retire')->assertOk()->assertJsonPath('data.template.status', 'retired');
        $this->withToken($token)->postJson($base.'/'.$template.'/retire')->assertOk()->assertJsonPath('data.template.status', 'retired');
    }

    public function test_only_one_active_template_is_allowed_and_snapshot_must_be_published(): void
    {
        [$school, $publisher] = $this->schoolWithRole('proprietor', 'template-unique@example.com', 'template-unique-password');
        $token = $this->loginAndSelect($publisher, $school, 'template-unique-password');
        $configuration = $this->configuration($school, $token);
        $snapshot = $this->publishedSnapshot($school, $publisher, $configuration);
        $base = '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/report-card-templates';
        $payload = $this->templatePayload((string) $snapshot->public_id);
        $first = $this->withToken($token)->postJson($base, $payload)->assertCreated()->json('data.template.id');
        $this->withToken($token)->postJson($base.'/'.$first.'/activate')->assertOk();
        $second = $this->withToken($token)->postJson($base, [...$payload, 'name' => 'Fictional Second Template'])->assertCreated()->json('data.template.id');
        $this->withToken($token)->postJson($base.'/'.$second.'/activate')->assertUnprocessable();

        $unpublishedRun = AcademicResultRun::create(['school_id' => $school->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'status' => 'completed', 'source_fingerprint' => hash('sha256', 'fictional-unpublished'), 'computed_count' => 0, 'not_computable_count' => 0, 'created_by' => $publisher->id]);
        AcademicResultGate::create(['school_id' => $school->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_result_run_id' => $unpublishedRun->id, 'status' => 'approved', 'reviewed_by' => $publisher->id, 'reviewed_at' => now()]);
        $unpublishedSnapshot = AcademicResultSnapshot::create(['school_id' => $school->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_result_gate_id' => AcademicResultGate::query()->where('academic_result_run_id', $unpublishedRun->id)->value('id'), 'academic_result_run_id' => $unpublishedRun->id, 'source_fingerprint' => hash('sha256', 'fictional-unpublished-snapshot'), 'snapshot_version' => 1, 'status' => 'published', 'published_by' => $publisher->id, 'published_at' => now()]);
        $this->withToken($token)->postJson($base, [...$payload, 'name' => 'Fictional Invalid Snapshot', 'snapshot_id' => $unpublishedSnapshot->public_id])->assertNotFound();
    }

    /** @param array<string, mixed> $configuration */
    private function publishedSnapshot(School $school, UserIdentity $publisher, array $configuration): AcademicResultSnapshot
    {
        $run = AcademicResultRun::create(['school_id' => $school->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'status' => 'completed', 'source_fingerprint' => hash('sha256', 'fictional-template-source'), 'computed_count' => 1, 'not_computable_count' => 0, 'created_by' => $publisher->id]);
        $gate = AcademicResultGate::create(['school_id' => $school->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_result_run_id' => $run->id, 'status' => 'published', 'published_by' => $publisher->id, 'published_at' => now()]);

        return AcademicResultSnapshot::create(['school_id' => $school->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_result_gate_id' => $gate->id, 'academic_result_run_id' => $run->id, 'source_fingerprint' => $run->source_fingerprint, 'snapshot_version' => 1, 'status' => 'published', 'published_by' => $publisher->id, 'published_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function templatePayload(string $snapshotId): array
    {
        return ['name' => 'Fictional Term Report', 'snapshot_id' => $snapshotId, 'sections' => [['key' => 'student_identity', 'label' => 'Student identity', 'sequence' => 1, 'fields' => [['key' => 'student_name', 'label' => 'Student name', 'type' => 'text', 'sequence' => 1]]], ['key' => 'subject_results', 'label' => 'Subject results', 'sequence' => 2, 'fields' => [['key' => 'percentage', 'label' => 'Percentage', 'type' => 'percentage', 'sequence' => 1]]]]];
    }
}
