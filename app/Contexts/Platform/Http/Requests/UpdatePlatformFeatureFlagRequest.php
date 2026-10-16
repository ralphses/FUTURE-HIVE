<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdatePlatformFeatureFlagRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'value' => ['sometimes'],
        ];
    }
}
