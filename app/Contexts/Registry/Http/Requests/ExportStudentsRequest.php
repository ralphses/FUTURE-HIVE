<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ExportStudentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'format' => ['required', 'string', 'in:csv,pdf,xlsx'],
            'q' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', 'string', 'in:pending,active,withdrawn,archived'],
            'term_id' => ['sometimes', 'uuid'],
            'class_arm_id' => ['sometimes', 'uuid'],
        ];
    }
}
