<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StorePlatformSubscriptionPlanRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:80', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/'],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:500'],
            'entitlement_keys' => ['required', 'array', 'min:1', 'max:100'],
            'entitlement_keys.*' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9._-]*$/'],
        ];
    }
}
