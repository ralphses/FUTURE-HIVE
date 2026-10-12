<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Contexts\Academic\Domain\Models\AcademicResultCorrection;
use App\Contexts\Academic\Domain\Models\AcademicResultGate;
use App\Contexts\Academic\Domain\Models\AcademicResultRun;
use App\Contexts\Academic\Domain\Models\AcademicResultSnapshot;

final class AcademicResultCorrectionTest extends AcademicScoreSubmissionTest
{
    public function test_published_snapshot_correction_is_requested_and_approved_without_mutating_the_source(): void
    {
        [$school, $principal] = $this->schoolWithRole('school_admin', 'correction-principal@example.com', 'correction-password');
        $token = $this->loginAndSelect($principal, $school, 'correction-password');
        $configuration = $this->configuration($school, $token);
        $run = AcademicResultRun::create([
            'school_id' => $school->id,
            'academic_session_id' => $configuration['session_id'],
            'academic_term_id' => $configuration['term_id'],
            'status' => 'completed',
            'source_fingerprint' => hash('sha256', 'fictional-correction-source'),
            'computed_count' => 1,
            'not_computable_count' => 0,
            'created_by' => $principal->id,
        ]);
        $gate = AcademicResultGate::create([
            'school_id' => $school->id,
            'academic_session_id' => $configuration['session_id'],
            'academic_term_id' => $configuration['term_id'],
            'academic_result_run_id' => $run->id,
            'status' => 'published',
            'published_by' => $principal->id,
            'published_at' => now(),
        ]);
        $snapshot = AcademicResultSnapshot::create([
            'school_id' => $school->id,
            'academic_session_id' => $configuration['session_id'],
            'academic_term_id' => $configuration['term_id'],
            'academic_result_gate_id' => $gate->id,
            'academic_result_run_id' => $run->id,
            'source_fingerprint' => $run->source_fingerprint,
            'status' => 'published',
            'published_by' => $principal->id,
            'published_at' => now(),
        ]);
        $base = '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'];

        $first = $this->withToken($token)->postJson($base.'/result-snapshots/'.$snapshot->public_id.'/corrections', ['reason' => 'Fictional correction reason'])->assertCreated()->assertJsonPath('data.correction.status', 'requested');
        $second = $this->withToken($token)->postJson($base.'/result-snapshots/'.$snapshot->public_id.'/corrections', ['reason' => 'A different reason'])->assertCreated();

        self::assertSame($first->json('data.correction.id'), $second->json('data.correction.id'));
        $correctionId = $first->json('data.correction.id');
        $this->withToken($token)->postJson($base.'/result-corrections/'.$correctionId.'/approve')->assertOk()->assertJsonPath('data.correction.status', 'approved');
        $this->withToken($token)->getJson($base.'/result-corrections/'.$correctionId)->assertOk()->assertJsonPath('data.correction.snapshot_id', (string) $snapshot->public_id);

        self::assertDatabaseCount('academic_result_corrections', 1);
        self::assertDatabaseHas('academic_result_snapshots', ['id' => $snapshot->id, 'status' => 'published', 'supersedes_snapshot_id' => null]);
        self::assertDatabaseHas('academic_result_corrections', ['id' => AcademicResultCorrection::query()->firstOrFail()->id, 'status' => 'approved']);
    }

    public function test_correction_requires_a_bounded_reason_and_published_source(): void
    {
        [$school, $principal] = $this->schoolWithRole('school_admin', 'invalid-correction@example.com', 'invalid-correction-password');
        $token = $this->loginAndSelect($principal, $school, 'invalid-correction-password');
        $configuration = $this->configuration($school, $token);

        $base = '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'];
        $this->withToken($token)->postJson($base.'/result-snapshots/not-a-snapshot/corrections', ['reason' => ''])->assertUnprocessable();
        self::assertDatabaseCount('academic_result_corrections', 0);
    }
}
