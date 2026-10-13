<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreFinancialLedgerBatchRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'source_type' => ['required', 'string', 'in:invoice,payment_allocation,opening_balance,adjustment'],
            'source_public_id' => ['required', 'uuid'],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['nullable', 'string', 'max:240', 'regex:/^[A-Za-z0-9 .,\'"()_:-]*$/'],
            'entries' => ['required', 'array', 'min:2', 'max:20'],
            'entries.*.account_code' => ['required', 'string', 'min:2', 'max:50', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'entries.*.direction' => ['required', 'string', 'in:debit,credit'],
            'entries.*.amount_minor_units' => ['required', 'integer', 'min:1'],
            'entries.*.description' => ['nullable', 'string', 'max:240', 'regex:/^[A-Za-z0-9 .,\'"()_:-]*$/'],
        ];
    }
}
