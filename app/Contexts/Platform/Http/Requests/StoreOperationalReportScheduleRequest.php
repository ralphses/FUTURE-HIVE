<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreOperationalReportScheduleRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'report' => ['required', 'string', Rule::in(['enrollment', 'attendance', 'academic', 'finance'])],
            'format' => ['required', 'string', Rule::in(['csv', 'pdf', 'xlsx'])],
            'frequency' => ['required', 'string', Rule::in(['daily', 'weekly', 'monthly'])],
            'session' => ['sometimes', 'nullable', 'string', 'max:64'],
            'term' => ['sometimes', 'nullable', 'string', 'max:64'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'idempotency_key' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ];
    }
}
