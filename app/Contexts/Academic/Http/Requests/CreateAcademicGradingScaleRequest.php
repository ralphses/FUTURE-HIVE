<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateAcademicGradingScaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'effective_start' => ['required', 'date_format:Y-m-d'],
            'effective_end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_start'],
            'bands' => ['required', 'array', 'min:1', 'max:101'],
            'bands.*.grade' => ['required', 'string', 'max:20'],
            'bands.*.label' => ['required', 'string', 'max:80'],
            'bands.*.minimum_percentage' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'bands.*.maximum_percentage' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'bands.*.is_passing' => ['required', 'boolean'],
            'bands.*.remark' => ['nullable', 'string', 'max:255'],
            'bands.*.sequence' => ['required', 'integer', 'min:1', 'max:101'],
        ];
    }
}
