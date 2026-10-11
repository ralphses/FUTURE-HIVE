<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ReplaceAcademicAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'entries' => ['required', 'array', 'max:500'],
            'entries.*.student_id' => ['required', 'uuid', 'distinct'],
            'entries.*.status' => ['required', 'string', 'in:present,absent,late,excused,unrecorded'],
            'entries.*.reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
