<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PutAcademicAssessmentScoreRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:missing,entered,absent,exempt'],
            'score' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'expected_version' => ['required', 'integer', 'min:0'],
        ];
    }
}
