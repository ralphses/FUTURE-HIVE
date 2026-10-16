<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreLessonPlanningSuggestionRequest extends FormRequest
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
            'topic_id' => ['nullable', 'uuid'],
            'objective_focus' => ['nullable', 'string', 'max:500'],
            'lesson_duration_minutes' => ['nullable', 'integer', 'min:15', 'max:240'],
        ];
    }
}
