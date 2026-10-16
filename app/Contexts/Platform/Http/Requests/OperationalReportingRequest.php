<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class OperationalReportingRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'session' => ['sometimes', 'nullable', 'string', 'max:64'],
            'term' => ['sometimes', 'nullable', 'string', 'max:64'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
