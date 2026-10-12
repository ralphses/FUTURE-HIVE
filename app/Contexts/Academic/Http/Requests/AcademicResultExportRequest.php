<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AcademicResultExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'format' => ['required', 'string', 'in:csv,pdf'],
            'snapshot' => ['nullable', 'uuid'],
            'student' => ['nullable', 'uuid'],
        ];
    }
}
