<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreFinancePostCloseAdjustmentRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['session_id' => ['required', 'uuid'], 'term_id' => ['required', 'uuid'], 'source_type' => ['required', 'string', 'in:invoice,payment_allocation,payment_evidence,receipt,ledger_batch'], 'source_public_id' => ['required', 'uuid'], 'amount_minor_units' => ['required', 'integer', 'min:1'], 'currency' => ['required', 'string', 'size:3', 'alpha'], 'reason' => ['required', 'string', 'min:3', 'max:240', 'regex:/^[A-Za-z0-9 .,\'"()_:-]+$/']];
    }
}
