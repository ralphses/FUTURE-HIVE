<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'preferences' => ['required', 'array'],
            'preferences.finance_reminders' => ['sometimes', 'boolean'],
            'preferences.academic_updates' => ['sometimes', 'boolean'],
            'preferences.attendance_updates' => ['sometimes', 'boolean'],
            'preferences.announcements' => ['sometimes', 'boolean'],
        ];
    }
}
