<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\ImportCommitAction;
use App\Contexts\Platform\Domain\Models\FinancialOpeningBalance;
use App\Contexts\Platform\Domain\Models\FinancialOpeningBalanceImport;
use App\Contexts\Platform\Domain\Models\ImportBatch;
use App\Contexts\Platform\Domain\Models\ImportDryRunReport;
use App\Contexts\Platform\Domain\Models\ImportDuplicateFinding;
use App\Contexts\Platform\Domain\Models\ImportMapping;
use App\Contexts\Platform\Domain\Models\ImportStagedRow;
use App\Contexts\Registry\Domain\Models\Student;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FinancialOpeningBalanceImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_opening_balances_are_archived_as_unverified_and_replay_is_idempotent(): void
    {
        [$school, $token, $membership, $identity] = $this->schoolToken();
        $batch = TenantContext::runInternal(TenantContext::fromMembership($membership), 'opening balance test setup', function () use ($school, $identity): string {
            $student = Student::query()->create(['school_id' => $school->id, 'student_number' => 'FIN-001', 'display_name' => 'Fictional Finance Learner', 'admission_date' => '2026-08-01', 'status' => 'active']);
            $batch = ImportBatch::query()->create(['school_id' => $school->id, 'template_key' => 'financial_opening_balances', 'template_version' => 'v1', 'original_filename' => 'balances.csv', 'format' => 'csv', 'size' => 100, 'checksum' => hash('sha256', 'balances'), 'uploaded_by' => $identity->id, 'status' => 'staged', 'row_count' => 1]);
            $values = ['student_number' => 'FIN-001', 'opening_balance' => '35000.00', 'currency' => 'NGN', 'as_of_date' => '2026-08-31', 'provenance_reference' => 'FIN-ARCHIVE-001'];
            $row = ImportStagedRow::query()->create(['import_batch_id' => $batch->id, 'school_id' => $school->id, 'row_number' => 2, 'cells' => $values, 'normalized_cells' => $values, 'status' => 'valid']);
            $mapping = ImportMapping::query()->create(['import_batch_id' => $batch->id, 'school_id' => $school->id, 'template_key' => 'financial_opening_balances', 'template_version' => 'v1', 'mapping_version' => 1, 'mappings' => array_map(static fn (string $field): array => ['source' => $field, 'target' => $field], array_keys($values)), 'status' => 'complete', 'created_by' => $identity->id]);
            $hashes = app(ImportCommitAction::class);
            ImportDryRunReport::query()->create(['import_batch_id' => $batch->id, 'school_id' => $school->id, 'template_key' => 'financial_opening_balances', 'template_version' => 'v1', 'mapping_version' => $mapping->mapping_version, 'validation_snapshot_hash' => $hashes->validationHash(ImportStagedRow::query()->whereKey($row->id)->get()), 'duplicate_snapshot_hash' => $hashes->duplicateHash(ImportDuplicateFinding::query()->where('import_batch_id', $batch->id)->get()), 'status' => 'ready', 'report' => ['ready_for_commit' => true], 'generated_by' => $identity->id]);

            return (string) $batch->public_id;
        });

        $path = '/api/v1/schools/'.$school->public_id.'/imports/'.$batch.'/financial-opening-balances';
        $first = $this->withToken($token)->postJson($path)->assertOk()->assertJsonPath('data.status', 'unverified')->assertJsonPath('data.counts.unverified', 1);
        $this->withToken($token)->postJson($path)->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->withToken($token)->getJson($path)->assertOk()->assertJsonPath('data.rows.0.status', 'unverified');
        self::assertDatabaseCount('financial_opening_balance_imports', 1);
        self::assertDatabaseCount('financial_opening_balances', 1);
        self::assertDatabaseCount('students', 1);
        self::assertSame('35000.00', (string) FinancialOpeningBalance::query()->firstOrFail()->opening_balance);
        self::assertSame('unverified', (string) FinancialOpeningBalanceImport::query()->firstOrFail()->status);
    }

    public function test_missing_or_inactive_students_are_rejected_without_creating_financial_transactions(): void
    {
        [$school, $token, $membership, $identity] = $this->schoolToken();
        $batch = TenantContext::runInternal(TenantContext::fromMembership($membership), 'opening balance rejection setup', function () use ($school, $identity): string {
            Student::query()->create(['school_id' => $school->id, 'student_number' => 'FIN-002', 'display_name' => 'Fictional Withdrawn Learner', 'admission_date' => '2026-08-01', 'status' => 'withdrawn']);
            $batch = ImportBatch::query()->create(['school_id' => $school->id, 'template_key' => 'financial_opening_balances', 'template_version' => 'v1', 'original_filename' => 'balances.csv', 'format' => 'csv', 'size' => 100, 'checksum' => hash('sha256', 'missing-balance'), 'uploaded_by' => $identity->id, 'status' => 'staged', 'row_count' => 1]);
            $values = ['student_number' => 'FIN-UNKNOWN', 'opening_balance' => '1200', 'currency' => 'NGN', 'as_of_date' => '2026-08-31', 'provenance_reference' => 'FIN-ARCHIVE-002'];
            $row = ImportStagedRow::query()->create(['import_batch_id' => $batch->id, 'school_id' => $school->id, 'row_number' => 2, 'cells' => $values, 'normalized_cells' => $values, 'status' => 'valid']);
            $mapping = ImportMapping::query()->create(['import_batch_id' => $batch->id, 'school_id' => $school->id, 'template_key' => 'financial_opening_balances', 'template_version' => 'v1', 'mapping_version' => 1, 'mappings' => array_map(static fn (string $field): array => ['source' => $field, 'target' => $field], array_keys($values)), 'status' => 'complete', 'created_by' => $identity->id]);
            $hashes = app(ImportCommitAction::class);
            ImportDryRunReport::query()->create(['import_batch_id' => $batch->id, 'school_id' => $school->id, 'template_key' => 'financial_opening_balances', 'template_version' => 'v1', 'mapping_version' => $mapping->mapping_version, 'validation_snapshot_hash' => $hashes->validationHash(ImportStagedRow::query()->whereKey($row->id)->get()), 'duplicate_snapshot_hash' => $hashes->duplicateHash(ImportDuplicateFinding::query()->where('import_batch_id', $batch->id)->get()), 'status' => 'ready', 'report' => ['ready_for_commit' => true], 'generated_by' => $identity->id]);

            return (string) $batch->public_id;
        });

        $response = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/imports/'.$batch.'/financial-opening-balances')->assertOk();
        $response->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rows.0.error_code', 'student_unavailable');
        self::assertDatabaseCount('financial_opening_balances', 1);
        self::assertDatabaseCount('students', 1);
        self::assertDatabaseMissing('financial_opening_balances', ['status' => 'unverified']);
    }

    /** @return array{0: School, 1: string, 2: SchoolMembership, 3: UserIdentity} */
    private function schoolToken(): array
    {
        $school = School::factory()->create();
        $identity = UserIdentity::factory()->withPassword('password')->create();
        $identity->contacts()->update(['canonical_value' => 'finance-'.uniqid().'@example.test', 'verified_at' => now()]);
        $membership = SchoolMembership::query()->create(['school_id' => $school->id, 'user_id' => $identity->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::query()->create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', 'school_admin')->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);
        $token = $this->postJson('/api/v1/auth/login', ['login' => $identity->contacts()->firstOrFail()->canonical_value, 'password' => 'password'])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return [$school, $token, $membership, $identity];
    }
}
