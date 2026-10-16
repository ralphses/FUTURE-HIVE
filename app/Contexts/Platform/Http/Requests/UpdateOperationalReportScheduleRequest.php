<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateOperationalReportScheduleRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'format' => ['sometimes', 'string', Rule::in(['csv', 'pdf', 'xlsx'])],
            'frequency' => ['sometimes', 'string', Rule::in(['daily', 'weekly', 'monthly'])],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }
}
