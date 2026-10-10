<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class TransferStudentEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'session_id' => ['required', 'uuid'],
            'term_id' => ['required', 'uuid'],
            'level_id' => ['required', 'uuid'],
            'section_id' => ['required', 'uuid'],
            'class_arm_id' => ['required', 'uuid'],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
