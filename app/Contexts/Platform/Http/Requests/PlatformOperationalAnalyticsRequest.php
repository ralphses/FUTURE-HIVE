<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PlatformOperationalAnalyticsRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'period_start' => ['sometimes', 'date_format:Y-m-d'],
            'period_end' => ['sometimes', 'date_format:Y-m-d'],
            'status' => ['sometimes', 'string', 'in:active,suspended,archived'],
            'subscription_state' => ['sometimes', 'string', 'in:trial,active,grace,expired,cancelled,unavailable'],
            'setup_status' => ['sometimes', 'string', 'in:pending,completed'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
