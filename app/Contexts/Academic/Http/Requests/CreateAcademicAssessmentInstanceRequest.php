<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateAcademicAssessmentInstanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['policy_id' => ['required', 'uuid'], 'grading_scale_id' => ['required', 'uuid'], 'title' => ['required', 'string', 'max:180'], 'assessment_date' => ['required', 'date_format:Y-m-d'], 'instructions' => ['nullable', 'string', 'max:5000']];
    }
}
