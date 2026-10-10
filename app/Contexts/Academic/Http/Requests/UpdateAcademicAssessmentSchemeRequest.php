<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateAcademicAssessmentSchemeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'total_marks' => ['required', 'integer', 'min:1', 'max:10000'],
            'components' => ['required', 'array', 'min:1', 'max:50'],
            'components.*.name' => ['required', 'string', 'max:80'],
            'components.*.category' => ['required', 'string', 'in:ca,test,exam'],
            'components.*.max_marks' => ['required', 'integer', 'min:1', 'max:10000'],
            'components.*.sequence' => ['required', 'integer', 'min:1', 'max:999'],
        ];
    }
}
