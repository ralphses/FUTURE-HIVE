<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StorePlatformSubscriptionRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'ends_at' => ['required', 'date', 'after:now'],
            'trial_ends_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            'grace_days' => ['sometimes', 'integer', 'min:0', 'max:90'],
        ];
    }
}
