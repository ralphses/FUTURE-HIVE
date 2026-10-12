<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpsertAcademicReportCardTemplateRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:180'],
            'snapshot_id' => ['required', 'uuid'],
            'sections' => ['required', 'array', 'min:1', 'max:10'],
            'sections.*.key' => ['required', 'string', 'in:student_identity,attendance,subject_results,remarks,signatures'],
            'sections.*.label' => ['required', 'string', 'max:120'],
            'sections.*.sequence' => ['required', 'integer', 'min:1', 'max:100'],
            'sections.*.is_visible' => ['sometimes', 'boolean'],
            'sections.*.fields' => ['required', 'array', 'min:1', 'max:30'],
            'sections.*.fields.*.key' => ['required', 'string', 'max:80'],
            'sections.*.fields.*.label' => ['required', 'string', 'max:120'],
            'sections.*.fields.*.type' => ['required', 'string', 'in:text,number,percentage,boolean,date'],
            'sections.*.fields.*.sequence' => ['required', 'integer', 'min:1', 'max:100'],
            'sections.*.fields.*.is_visible' => ['sometimes', 'boolean'],
        ];
    }
}
