<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreSchemeTopicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['title' => ['required', 'string', 'max:180'], 'description' => ['nullable', 'string', 'max:4000'], 'sequence' => ['required', 'integer', 'min:1', 'max:1000'], 'teaching_week' => ['nullable', 'integer', 'min:1', 'max:60']];
    }
}
