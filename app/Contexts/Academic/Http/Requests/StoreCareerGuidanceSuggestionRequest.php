<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreCareerGuidanceSuggestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'career_interest' => ['nullable', 'string', 'max:120'],
            'subject_interest' => ['nullable', 'string', 'max:120'],
            'institution_reference' => ['nullable', 'string', 'max:120'],
        ];
    }
}
