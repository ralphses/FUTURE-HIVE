<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreCounsellorAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'staff_id' => ['required', 'uuid'],
            'student_id' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
