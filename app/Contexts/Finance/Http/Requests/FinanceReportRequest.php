<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class FinanceReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'term_id' => ['sometimes', 'uuid'],
            'fee_plan_id' => ['sometimes', 'uuid'],
            'category_code' => ['sometimes', 'string', 'max:40'],
            'currency' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'format' => ['sometimes', 'string', 'in:csv,pdf,xlsx'],
            'report' => ['sometimes', 'string', 'in:collections,arrears,fee-plans'],
        ];
    }
}
