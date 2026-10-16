<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ReopenPlatformSubscriptionGraceRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['grace_days' => ['required', 'integer', 'min:1', 'max:90']];
    }
}
