<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ResolveFinanceReconciliationExceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:240', "regex:/^[A-Za-z0-9 .,\'\"()_:-]+$/"]];
    }
}
