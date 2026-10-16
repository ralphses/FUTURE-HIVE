<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class OperationalReportExportRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'report' => ['required', 'string', Rule::in(['enrollment', 'attendance', 'academic', 'finance'])],
            'format' => ['required', 'string', Rule::in(['csv', 'pdf', 'xlsx'])],
            'session' => ['sometimes', 'nullable', 'string', 'max:64'],
            'term' => ['sometimes', 'nullable', 'string', 'max:64'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
        ];
    }
}
