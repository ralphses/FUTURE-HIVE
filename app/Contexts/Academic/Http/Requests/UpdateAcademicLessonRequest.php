<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateAcademicLessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['lesson_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'teaching_week' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:60'], 'title' => ['sometimes', 'string', 'max:180'], 'objectives' => ['sometimes', 'nullable', 'string', 'max:10000'], 'materials' => ['sometimes', 'nullable', 'string', 'max:10000'], 'activities' => ['sometimes', 'nullable', 'string', 'max:20000'], 'evaluation' => ['sometimes', 'nullable', 'string', 'max:10000'], 'assignment' => ['sometimes', 'nullable', 'string', 'max:10000']];
    }
}
