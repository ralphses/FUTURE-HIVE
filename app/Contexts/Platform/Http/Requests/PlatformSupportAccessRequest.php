<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PlatformSupportAccessRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'purpose' => ['required', 'string', 'max:240'],
            'scope' => ['required', 'string', 'in:metadata.read,operations.read,import.diagnostics'],
            'expires_at' => ['required', 'date', 'after:now', 'before_or_equal:+24 hours'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ];
    }
}
