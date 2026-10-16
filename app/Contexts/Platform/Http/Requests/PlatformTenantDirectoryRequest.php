<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class PlatformTenantDirectoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', 'in:active,suspended,archived'],
            'subscription_state' => ['sometimes', 'string', 'in:trial,active,grace,expired,cancelled,unavailable'],
            'setup_status' => ['sometimes', 'string', 'in:pending,completed'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
