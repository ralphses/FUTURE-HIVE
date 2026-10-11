<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateSchemeTopicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['title' => ['sometimes', 'string', 'max:180'], 'description' => ['sometimes', 'nullable', 'string', 'max:4000'], 'sequence' => ['sometimes', 'integer', 'min:1', 'max:1000'], 'teaching_week' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:60']];
    }
}
