<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreFinanceReconciliationRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'description' => ['nullable', 'string', 'max:240', "regex:/^[A-Za-z0-9 .,\'\"()_:-]+$/"],
            'sources' => ['required', 'array', 'min:1', 'max:500'],
            'sources.*.source_type' => ['required', 'string', 'in:payment_intent,payment_webhook,payment_allocation,payment_evidence,receipt,ledger_batch'],
            'sources.*.source_public_id' => ['required', 'uuid'],
            'sources.*.amount_minor_units' => ['required', 'integer', 'min:1'],
            'sources.*.currency' => ['required', 'string', 'size:3', 'alpha'],
            'sources.*.settlement_reference' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9 ._:-]+$/'],
        ];
    }
}
