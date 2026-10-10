<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class UpsertAcademicPromotionRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'source_level_id' => ['required', 'uuid'],
            'target_level_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'criteria' => ['required', 'array', 'min:1', 'max:10'],
            'criteria.*.metric' => ['required', 'string', 'in:overall_percentage,minimum_passing_subjects,attendance_percentage'],
            'criteria.*.operator' => ['required', 'string', 'in:gte'],
            'criteria.*.threshold' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'criteria.*.required' => ['required', 'boolean'],
            'criteria.*.sequence' => ['required', 'integer', 'min:1', 'max:10'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    protected function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('criteria')) {
                return;
            }
            $criteria = $this->input('criteria', []);
            $metrics = array_map(static fn (array $criterion): string => (string) ($criterion['metric'] ?? ''), $criteria);
            $sequences = array_map(static fn (array $criterion): int => (int) ($criterion['sequence'] ?? 0), $criteria);
            if (count($metrics) !== count(array_unique($metrics))) {
                $validator->errors()->add('criteria', 'Each promotion metric may appear only once.');
            }
            if (count($sequences) !== count(array_unique($sequences))) {
                $validator->errors()->add('criteria', 'Each criterion sequence must be unique.');
            }
        }];
    }
}
