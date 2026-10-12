<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PutAcademicAssessmentScoresRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'entries' => ['required', 'array', 'min:1', 'max:500'],
            'entries.*.student_id' => ['required', 'uuid'],
            'entries.*.component_id' => ['required', 'uuid'],
            'entries.*.status' => ['required', 'string', 'in:missing,entered,absent,exempt'],
            'entries.*.score' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'entries.*.expected_version' => ['required', 'integer', 'min:0'],
        ];
    }
}
