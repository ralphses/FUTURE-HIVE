<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAcademicSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:120'], 'code' => ['nullable', 'string', 'max:50'], 'sequence' => ['required', 'integer', 'min:1', 'max:999']];
    }
}
