<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreFeePlanRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'session_id' => ['required', 'uuid'],
            'term_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:160'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'level_id' => ['nullable', 'uuid'],
            'section_id' => ['nullable', 'uuid'],
            'class_arm_id' => ['nullable', 'uuid'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.category_id' => ['required', 'uuid'],
            'lines.*.amount_minor_units' => ['required', 'integer', 'min:0', 'max:9223372036854775807'],
            'lines.*.sequence' => ['required', 'integer', 'min:1', 'max:1000'],
            'lines.*.is_required' => ['required', 'boolean'],
        ];
    }
}
