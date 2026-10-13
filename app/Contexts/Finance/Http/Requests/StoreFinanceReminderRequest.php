<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreFinanceReminderRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['invoice_id' => ['required', 'uuid'], 'recipient_id' => ['required', 'uuid'], 'reminder_type' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'], 'period_key' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/']];
    }
}
