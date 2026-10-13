<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreFeePaymentAllocationRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'payment_reference' => ['required', 'string', 'min:3', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'payment_amount_minor_units' => ['required', 'integer', 'min:1'],
            'amount_minor_units' => ['required', 'integer', 'min:1'],
            'line_id' => ['nullable', 'uuid'],
        ];
    }
}
