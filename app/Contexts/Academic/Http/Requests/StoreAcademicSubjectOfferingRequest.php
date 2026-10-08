<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAcademicSubjectOfferingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'class_arm_id' => ['required', 'uuid'],
            'subject_id' => ['required', 'uuid'],
            'display_order' => ['nullable', 'integer', 'min:1', 'max:999'],
        ];
    }
}
