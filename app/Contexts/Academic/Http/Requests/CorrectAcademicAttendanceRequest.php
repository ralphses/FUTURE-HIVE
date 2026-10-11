<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CorrectAcademicAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'entry_id' => ['required', 'uuid'],
            'status' => ['required', 'string', 'in:present,absent,late,excused,unrecorded'],
            'reason' => ['nullable', 'string', 'max:255'],
            'correction_reason' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }
}
