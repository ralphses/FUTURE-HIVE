<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StorePlatformFeatureFlagRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'flag_key' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9.-]*$/'],
            'label' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:500'],
            'value' => ['required'],
        ];
    }
}
