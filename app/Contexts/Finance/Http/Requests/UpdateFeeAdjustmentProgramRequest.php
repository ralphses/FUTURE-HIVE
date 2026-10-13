<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateFeeAdjustmentProgramRequest extends FormRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/'],
            'name' => ['sometimes', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'adjustment_type' => ['sometimes', 'string', 'in:discount,scholarship,waiver'],
            'calculation_method' => ['sometimes', 'string', 'in:fixed,percentage'],
            'fixed_amount_minor_units' => ['nullable', 'integer', 'min:0', 'max:9223372036854775807'],
            'percentage' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'allows_multiple' => ['sometimes', 'boolean'],
            'eligibility' => ['nullable', 'array', 'max:20'],
        ];
    }
}
