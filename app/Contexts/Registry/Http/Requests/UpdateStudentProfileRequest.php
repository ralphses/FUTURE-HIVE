<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateStudentProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'legal_name' => ['required', 'string', 'max:160'],
            'preferred_name' => ['nullable', 'string', 'max:160'],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'gender' => ['nullable', 'string', 'in:female,male,non_binary,undisclosed'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
