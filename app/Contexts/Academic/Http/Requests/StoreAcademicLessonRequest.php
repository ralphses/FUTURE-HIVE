<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreAcademicLessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['topic_id' => ['nullable', 'uuid'], 'lesson_date' => ['nullable', 'date_format:Y-m-d'], 'teaching_week' => ['nullable', 'integer', 'min:1', 'max:60'], 'title' => ['required', 'string', 'max:180'], 'objectives' => ['nullable', 'string', 'max:10000'], 'materials' => ['nullable', 'string', 'max:10000'], 'activities' => ['nullable', 'string', 'max:20000'], 'evaluation' => ['nullable', 'string', 'max:10000'], 'assignment' => ['nullable', 'string', 'max:10000']];
    }
}
