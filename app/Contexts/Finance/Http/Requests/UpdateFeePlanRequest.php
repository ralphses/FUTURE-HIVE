<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateFeePlanRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:160'],
            'currency' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'effective_from' => ['sometimes', 'date_format:Y-m-d'],
            'effective_to' => ['sometimes', 'date_format:Y-m-d'],
            'lines' => ['sometimes', 'array', 'min:1', 'max:100'],
            'lines.*.category_id' => ['required', 'uuid'],
            'lines.*.amount_minor_units' => ['required', 'integer', 'min:0', 'max:9223372036854775807'],
            'lines.*.sequence' => ['required', 'integer', 'min:1', 'max:1000'],
            'lines.*.is_required' => ['required', 'boolean'],
        ];
    }
}
