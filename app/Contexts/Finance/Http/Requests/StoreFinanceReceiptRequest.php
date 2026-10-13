<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreFinanceReceiptRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'source_type' => ['required', 'string', 'in:payment_allocation,payment_evidence'],
            'source_public_id' => ['required', 'uuid'],
            'description' => ['nullable', 'string', 'max:240', 'regex:/^[A-Za-z0-9 .,\'"()_:-]*$/'],
        ];
    }
}
