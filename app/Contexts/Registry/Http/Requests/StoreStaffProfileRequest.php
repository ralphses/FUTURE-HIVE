<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStaffProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'membership_id' => ['required', 'uuid'],
            'staff_number' => ['required', 'string', 'max:50'],
            'legal_name' => ['required', 'string', 'max:160'],
            'preferred_name' => ['nullable', 'string', 'max:160'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'department' => ['nullable', 'string', 'max:120'],
        ];
    }
}
