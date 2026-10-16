<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PlatformBillingPaymentRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['provider' => ['required', 'string', 'in:deterministic'], 'amount_minor_units' => ['required', 'integer', 'min:1', 'max:999999999999'], 'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'], 'idempotency_key' => ['sometimes', 'string', 'min:8', 'max:160']];
    }
}
