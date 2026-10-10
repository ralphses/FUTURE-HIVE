<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateClassTeacherAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'effective_start' => ['sometimes', 'date_format:Y-m-d'],
            'effective_end' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
