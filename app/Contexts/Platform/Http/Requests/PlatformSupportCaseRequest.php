<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PlatformSupportCaseRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:160'],
            'category' => ['required', 'string', 'in:technical,access,import,academic,finance,other'],
            'severity' => ['required', 'string', 'in:low,medium,high,critical'],
            'reason_code' => ['nullable', 'string', 'max:80', 'regex:/^[a-z][a-z0-9._-]*$/'],
        ];
    }
}
