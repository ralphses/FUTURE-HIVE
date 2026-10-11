<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Platform\Domain\Models\FinancialOpeningBalance;
use App\Contexts\Platform\Domain\Models\FinancialOpeningBalanceImport;
use App\Contexts\Platform\Domain\Models\ImportBatch;
use App\Contexts\Platform\Domain\Models\ImportCommit;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Imports\FinancialOpeningBalanceReferenceResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

final class FinancialOpeningBalanceImportAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly ImportCommitAction $commits,
        private readonly FinancialOpeningBalanceReferenceResolver $references,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /**
     * @return array{
     *     id: string,
     *     template_key: string,
     *     template_version: string,
     *     status: string,
     *     counts: array<string, int>,
     *     errors: array<string, int>,
     *     rows: list<array{row_number: int, status: string, error_code: ?string, id: string}>
     * }
     */
    public function import(Authenticatable $actor, string $school, string $batchPublicId): array
    {
        $this->authorize($actor, $school, 'imports.financial-opening-balances.manage');

        return DB::transaction(function () use ($actor, $batchPublicId): array {
            $batch = ImportBatch::query()->where('public_id', $batchPublicId)->lockForUpdate()->first();
            if (! $batch instanceof ImportBatch || $batch->template_key !== 'financial_opening_balances' || $batch->status !== 'staged') {
                throw new ModelNotFoundException;
            }

            $existing = FinancialOpeningBalanceImport::query()->where('import_batch_id', $batch->getKey())->first();
            if ($existing instanceof FinancialOpeningBalanceImport) {
                return $this->data($existing->load('balances'));
            }

            $commit = ImportCommit::query()->where('import_batch_id', $batch->getKey())->latest('id')->first();
            [, $rows] = $this->commits->readyInputs($batch);
            $archive = FinancialOpeningBalanceImport::query()->create([
                'import_batch_id' => $batch->getKey(),
                'import_commit_id' => $commit?->getKey(),
                'template_key' => (string) $batch->template_key,
                'template_version' => (string) $batch->template_version,
                'status' => 'unverified',
                'created_by' => (int) $actor->getAuthIdentifier(),
                'request_id' => Context::get('request_id'),
            ]);
            $accepted = 0;
            $rejected = 0;
            $errors = [];

            foreach ($rows as $row) {
                $result = $this->references->resolve($row->normalized_cells ?? []);
                FinancialOpeningBalance::query()->create([
                    'financial_opening_balance_import_id' => $archive->getKey(),
                    'row_number' => (int) $row->row_number,
                    'student_id' => $result['student_id'],
                    'opening_balance' => $result['opening_balance'],
                    'currency' => $result['currency'],
                    'as_of_date' => $result['as_of_date'],
                    'provenance_reference' => $result['provenance_reference'],
                    'status' => $result['error_code'] === null ? 'unverified' : 'rejected',
                    'error_code' => $result['error_code'],
                ]);
                if ($result['error_code'] === null) {
                    $accepted++;
                } else {
                    $rejected++;
                    $errors[$result['error_code']] = ($errors[$result['error_code']] ?? 0) + 1;
                }
            }

            $archive->forceFill([
                'status' => $rejected === 0 ? 'unverified' : 'rejected',
                'counts' => ['total' => $rows->count(), 'unverified' => $accepted, 'rejected' => $rejected],
                'error_summary' => $errors,
            ])->save();
            $this->audit->execute(new AuditEventData(
                action: 'financial_opening_balance_import.completed',
                subjectType: 'financial_opening_balance_import',
                subjectPublicId: (string) $archive->public_id,
                schoolId: TenantContext::require()->schoolId,
                actorId: (int) $actor->getAuthIdentifier(),
                requestId: Context::get('request_id'),
                authorizationContext: ['permission' => 'imports.financial-opening-balances.manage'],
                metadata: ['batch_id' => (string) $batch->public_id, 'unverified' => $accepted, 'rejected' => $rejected],
                requiresSchoolContext: true,
            ));

            return $this->data($archive->load('balances'));
        });
    }

    /**
     * @return array{
     *     id: string,
     *     template_key: string,
     *     template_version: string,
     *     status: string,
     *     counts: array<string, int>,
     *     errors: array<string, int>,
     *     rows: list<array{row_number: int, status: string, error_code: ?string, id: string}>
     * }
     */
    public function show(Authenticatable $actor, string $school, string $batchPublicId): array
    {
        $this->authorize($actor, $school, 'imports.financial-opening-balances.read');
        $batch = ImportBatch::query()->where('public_id', $batchPublicId)->first();
        $archive = $batch instanceof ImportBatch ? FinancialOpeningBalanceImport::query()->where('import_batch_id', $batch->getKey())->first() : null;
        if (! $archive instanceof FinancialOpeningBalanceImport) {
            throw new ModelNotFoundException;
        }

        return $this->data($archive->load('balances'));
    }

    /** @return array<string, mixed> */
    private function data(FinancialOpeningBalanceImport $archive): array
    {
        $counts = collect((array) ($archive->counts ?? []))->mapWithKeys(static fn (mixed $value, mixed $key): array => [(string) $key => (int) $value])->all();
        $errors = collect((array) ($archive->error_summary ?? []))->mapWithKeys(static fn (mixed $value, mixed $key): array => [(string) $key => (int) $value])->all();

        return [
            'id' => (string) $archive->public_id,
            'template_key' => (string) $archive->template_key,
            'template_version' => (string) $archive->template_version,
            'status' => (string) $archive->status,
            'counts' => $counts,
            'errors' => $errors,
            'rows' => $archive->balances->map(fn (FinancialOpeningBalance $balance): array => [
                'row_number' => (int) $balance->row_number,
                'status' => (string) $balance->status,
                'error_code' => $balance->error_code === null ? null : (string) $balance->error_code,
                'id' => (string) $balance->public_id,
            ])->values()->all(),
        ];
    }

    private function authorize(Authenticatable $actor, string $school, string $permission): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
    }
}
