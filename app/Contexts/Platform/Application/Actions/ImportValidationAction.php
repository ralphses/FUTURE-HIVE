<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Platform\Application\Services\ImportTemplateCatalog;
use App\Contexts\Platform\Domain\Models\ImportBatch;
use App\Contexts\Platform\Domain\Models\ImportMapping;
use App\Contexts\Platform\Domain\Models\ImportStagedRow;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Contacts\ContactNormalizer;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class ImportValidationAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly ImportTemplateCatalog $catalog,
        private readonly ContactNormalizer $contactNormalizer,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return array<string, mixed> */
    public function validate(Authenticatable $actor, string $school, string $import): array
    {
        $this->authorize($actor, $school, 'imports.validation.manage');

        return DB::transaction(function () use ($actor, $import): array {
            $batch = $this->batch($import, true);
            $mapping = $this->mapping($batch);
            $rows = $batch->rows()->orderBy('row_number')->limit((int) config('imports.max_rows', 5000))->get();
            $valid = 0;
            $invalid = 0;

            foreach ($rows as $row) {
                $result = $this->validateRow($batch, $mapping, $row);
                $row->update([
                    'normalized_cells' => $result['values'],
                    'status' => $result['errors'] === [] ? 'valid' : 'invalid',
                    'error_metadata' => $result['errors'] === [] ? null : $result['errors'],
                ]);
                $result['errors'] === [] ? $valid++ : $invalid++;
            }

            $this->audit->execute(new AuditEventData(
                action: 'import.validation.completed',
                subjectType: 'import_batch',
                subjectPublicId: (string) $batch->public_id,
                schoolId: TenantContext::require()->schoolId,
                actorId: (int) $actor->getAuthIdentifier(),
                requestId: Context::get('request_id'),
                authorizationContext: ['permission' => 'imports.validation.manage'],
                metadata: ['mapping_version' => (int) $mapping->mapping_version, 'row_count' => $rows->count(), 'valid_count' => $valid, 'invalid_count' => $invalid],
                requiresSchoolContext: true,
            ));

            return $this->data($batch, $mapping);
        });
    }

    /** @return array<string, mixed> */
    public function show(Authenticatable $actor, string $school, string $import): array
    {
        $this->authorize($actor, $school, 'imports.validation.read');
        $batch = $this->batch($import);
        $mapping = $this->mapping($batch);

        return $this->data($batch, $mapping);
    }

    /** @return array<string, mixed> */
    private function data(ImportBatch $batch, ImportMapping $mapping): array
    {
        $rows = $batch->rows()->orderBy('row_number')->limit((int) config('imports.max_rows', 5000))->get();
        $valid = $rows->where('status', 'valid')->count();
        $invalid = $rows->where('status', 'invalid')->count();

        return [
            'import_id' => (string) $batch->public_id,
            'mapping_version' => (int) $mapping->mapping_version,
            'ready_for_duplicate_detection' => $rows->isNotEmpty() && $invalid === 0 && $valid === $rows->count(),
            'summary' => ['total' => $rows->count(), 'valid' => $valid, 'invalid' => $invalid, 'pending' => $rows->where('status', 'pending')->count()],
            'rows' => $rows->map(fn (ImportStagedRow $row): array => [
                'row_number' => (int) $row->row_number,
                'status' => (string) $row->status,
                'values' => $this->safeValues($row->normalized_cells ?? []),
                'errors' => $row->error_metadata ?? [],
            ])->values()->all(),
        ];
    }

    /** @return array{values: array<string, string>, errors: list<array{field: string, code: string, message: string}>} */
    private function validateRow(ImportBatch $batch, ImportMapping $mapping, ImportStagedRow $row): array
    {
        $sourceValues = $row->cells;
        $mapped = [];
        foreach ($mapping->mappings as $item) {
            $mapped[$item['target']] = trim((string) ($sourceValues[$item['source']] ?? ''));
        }

        $template = $this->catalog->get((string) $batch->template_key);
        $fields = collect($template['fields'])->keyBy('name');
        $values = [];
        $errors = [];

        foreach ($fields as $fieldName => $field) {
            $value = $mapped[$fieldName] ?? '';
            if ($field['required'] && $value === '') {
                $errors[] = ['field' => $fieldName, 'code' => 'required', 'message' => 'This field is required.'];

                continue;
            }
            if ($value === '') {
                continue;
            }

            $normalized = $this->normalizeField((string) $batch->template_key, (string) $fieldName, $value);
            if ($normalized['error'] !== null) {
                $errors[] = ['field' => $fieldName, 'code' => $normalized['error']['code'], 'message' => $normalized['error']['message']];

                continue;
            }
            $values[$fieldName] = $normalized['value'];
        }

        if ((string) $batch->template_key === 'assessment_scores') {
            $status = $values['status'] ?? null;
            $hasScore = array_key_exists('score', $values);
            if ($status === 'entered' && ! $hasScore) {
                $errors[] = ['field' => 'score', 'code' => 'required_for_entered', 'message' => 'An entered score is required.'];
            }
            if ($status !== null && $status !== 'entered' && $hasScore) {
                $errors[] = ['field' => 'score', 'code' => 'score_not_allowed', 'message' => 'Only entered rows may contain a numeric score.'];
            }
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /** @return array{value: string|null, error: array{code: string, message: string}|null} */
    private function normalizeField(string $template, string $field, string $value): array
    {
        $maxLengths = [
            'student_number' => 100,
            'display_name' => 255,
            'guardian_display_name' => 255,
            'legal_name' => 255,
            'preferred_name' => 255,
            'guardian_contact' => 255,
            'membership_contact' => 255,
            'job_title' => 120,
            'department' => 120,
            'metadata' => 1000,
            'academic_session' => 64,
            'academic_term' => 64,
            'subject_offering' => 64,
            'policy_version' => 64,
            'score' => 32,
            'max_marks' => 32,
            'grade' => 32,
            'remark' => 255,
            'provenance_reference' => 160,
            'currency' => 3,
            'opening_balance' => 32,
        ];
        if (isset($maxLengths[$field]) && mb_strlen($value) > $maxLengths[$field]) {
            return ['value' => null, 'error' => ['code' => 'too_long', 'message' => 'This field exceeds the allowed length.']];
        }

        if ($field === 'admission_date') {
            try {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
            } catch (InvalidFormatException) {
                return ['value' => null, 'error' => ['code' => 'invalid_date', 'message' => 'Use a valid date in YYYY-MM-DD format.']];
            }
            if ($date === null || $date->format('Y-m-d') !== $value) {
                return ['value' => null, 'error' => ['code' => 'invalid_date', 'message' => 'Use a valid date in YYYY-MM-DD format.']];
            }

            return ['value' => $date->format('Y-m-d'), 'error' => null];
        }

        if ($field === 'as_of_date') {
            try {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
            } catch (InvalidFormatException) {
                return ['value' => null, 'error' => ['code' => 'invalid_date', 'message' => 'Use a valid date in YYYY-MM-DD format.']];
            }
            if ($date === null || $date->format('Y-m-d') !== $value) {
                return ['value' => null, 'error' => ['code' => 'invalid_date', 'message' => 'Use a valid date in YYYY-MM-DD format.']];
            }

            return ['value' => $date->format('Y-m-d'), 'error' => null];
        }

        if (in_array($field, ['guardian_contact', 'membership_contact'], true)) {
            try {
                return ['value' => $this->contactNormalizer->normalize($value)->value, 'error' => null];
            } catch (InvalidArgumentException) {
                return ['value' => null, 'error' => ['code' => 'invalid_contact', 'message' => 'The contact value is invalid.']];
            }
        }

        if ($field === 'relationship_type' && ! in_array($value, ['parent', 'guardian', 'grandparent', 'foster_parent', 'other'], true)) {
            return ['value' => null, 'error' => ['code' => 'unsupported_value', 'message' => 'The relationship type is not supported.']];
        }

        if ($field === 'employment_status' && ! in_array($value, ['pending', 'active', 'suspended', 'ended'], true)) {
            return ['value' => null, 'error' => ['code' => 'unsupported_value', 'message' => 'The employment state is not supported.']];
        }

        if ($field === 'status' && $template === 'assessment_scores' && ! in_array($value, ['entered', 'absent', 'exempt', 'missing'], true)) {
            return ['value' => null, 'error' => ['code' => 'unsupported_value', 'message' => 'The score state is not supported.']];
        }

        if ($field === 'component_sequence' && (! ctype_digit($value) || (int) $value < 1)) {
            return ['value' => null, 'error' => ['code' => 'invalid_sequence', 'message' => 'Use a positive component sequence number.']];
        }

        if (in_array($field, ['academic_session', 'academic_term', 'subject_offering', 'policy_version'], true) && ! preg_match('/^[0-9a-f-]{36}$/i', $value)) {
            return ['value' => null, 'error' => ['code' => 'invalid_public_id', 'message' => 'Use a valid public identifier.']];
        }

        if (in_array($field, ['score', 'max_marks'], true) && (! is_numeric($value) || (float) $value < 0)) {
            return ['value' => null, 'error' => ['code' => 'invalid_number', 'message' => 'Use a non-negative number.']];
        }

        if ($field === 'score' && $template === 'assessment_scores') {
            try {
                return ['value' => BigDecimal::of($value)->toScale(2, RoundingMode::HalfUp)->toString(), 'error' => null];
            } catch (MathException) {
                return ['value' => null, 'error' => ['code' => 'invalid_number', 'message' => 'Use a non-negative score.']];
            }
        }

        if ($field === 'opening_balance' && (! preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $value) || (float) $value < 0)) {
            return ['value' => null, 'error' => ['code' => 'invalid_amount', 'message' => 'Use a non-negative amount with up to two decimal places.']];
        }

        if ($field === 'currency' && ! preg_match('/^[A-Z]{3}$/', $value)) {
            return ['value' => null, 'error' => ['code' => 'invalid_currency', 'message' => 'Use a three-letter uppercase currency code.']];
        }

        return ['value' => $value, 'error' => null];
    }

    /** @param array<string, string> $values
     * @return array<string, string>
     */
    private function safeValues(array $values): array
    {
        $safe = [];
        foreach ($values as $field => $value) {
            $safe[$field] = str_contains($field, 'contact') ? '[redacted]' : $value;
        }

        return $safe;
    }

    private function batch(string $import, bool $lock = false): ImportBatch
    {
        $query = ImportBatch::query()->where('public_id', $import);
        if ($lock) {
            $query->lockForUpdate();
        }
        $batch = $query->first();
        if (! $batch instanceof ImportBatch || $batch->status !== 'staged') {
            throw new ModelNotFoundException;
        }

        return $batch;
    }

    private function mapping(ImportBatch $batch): ImportMapping
    {
        $mapping = ImportMapping::query()->where('import_batch_id', $batch->getKey())->latest('mapping_version')->first();
        if (! $mapping instanceof ImportMapping || $mapping->status !== 'complete') {
            throw ValidationException::withMessages(['mapping' => ['A complete import mapping is required before validation.']]);
        }

        return $mapping;
    }

    private function authorize(Authenticatable $actor, string $school, string $permission): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
    }
}
