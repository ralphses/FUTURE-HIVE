<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreAcademicInsightSuggestionRequest extends FormRequest
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
            'offering_id' => ['required', 'uuid'],
            'student_id' => ['nullable', 'uuid'],
            'insight_focus' => ['nullable', 'string', 'max:160'],
        ];
    }
}
