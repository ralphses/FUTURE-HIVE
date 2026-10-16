<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreNotificationTemplateRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['template_key' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9._-]+$/'], 'channel' => ['required', 'string', Rule::in(['in_app', 'email', 'sms', 'push'])], 'title' => ['required', 'string', 'max:160'], 'body' => ['required', 'string', 'max:4000']];
    }
}
