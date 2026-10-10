<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateAcademicAssessmentPolicyVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:120'],
            'effective_start' => ['required', 'date_format:Y-m-d'],
            'effective_end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_start'],
        ];
    }
}
