<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'student_number' => ['required', 'string', 'max:50'],
            'display_name' => ['required', 'string', 'max:160'],
            'admission_date' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
