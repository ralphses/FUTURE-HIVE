<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreNotificationRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['template_id' => ['required', 'uuid'], 'channel' => ['required', 'string', Rule::in(['in_app', 'email', 'sms', 'push'])], 'recipient_type' => ['required', 'string', Rule::in(['guardian', 'staff'])], 'recipient_id' => ['required', 'uuid'], 'source_type' => ['required', 'string', Rule::in(['manual', 'announcement', 'fee_reminder'])], 'source_id' => ['nullable', 'uuid'], 'idempotency_key' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/']];
    }
}
