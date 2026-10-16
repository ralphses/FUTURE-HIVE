<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PrincipalReviewDashboardRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'session' => ['sometimes', 'nullable', 'string', 'max:64'],
            'term' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
