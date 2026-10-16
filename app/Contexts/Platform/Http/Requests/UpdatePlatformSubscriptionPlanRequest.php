<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdatePlatformSubscriptionPlanRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'entitlement_keys' => ['sometimes', 'array', 'min:1', 'max:100'],
            'entitlement_keys.*' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9._-]*$/'],
        ];
    }
}
