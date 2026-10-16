<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateAnnouncementAudienceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'session_id' => ['required', 'uuid'],
            'term_id' => ['required', 'uuid'],
            'class_arm_id' => ['nullable', 'uuid', 'required_without:subject_offering_id'],
            'subject_offering_id' => ['nullable', 'uuid', 'required_without:class_arm_id'],
        ];
    }
}
