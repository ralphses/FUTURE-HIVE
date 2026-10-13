<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreFeeInvoiceAdjustmentRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['program_id' => ['required', 'uuid'], 'line_id' => ['nullable', 'uuid'], 'reason' => ['required', 'string', 'min:3', 'max:500']];
    }
}
