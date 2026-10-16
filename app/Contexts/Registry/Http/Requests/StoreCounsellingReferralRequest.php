<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreCounsellingReferralRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'case_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:200'],
            'purpose' => ['required', 'string', 'max:1000'],
            'urgency' => ['required', 'string', 'in:low,normal,high,critical'],
            'reason_code' => ['required', 'string', 'max:80'],
            'destination_scope' => ['required', 'string', 'in:counselling_staff,school_leadership,safeguarding_leadership'],
        ];
    }
}
