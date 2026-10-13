<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreFeePaymentEvidenceRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'amount_minor_units' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'payment_date' => ['required', 'date_format:Y-m-d'],
            'reference' => ['required', 'string', 'min:3', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'note' => ['nullable', 'string', 'max:240', 'regex:/^[A-Za-z0-9 .,\'\"()_:-]+$/'],
            'evidence_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'extensions:pdf,jpg,jpeg,png,webp', 'max:5120'],
        ];
    }
}
