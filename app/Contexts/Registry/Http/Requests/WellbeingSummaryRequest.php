<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class WellbeingSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
