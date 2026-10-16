<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StorePublicDemoEnquiryRequest extends FormRequest
{
    public function idempotencyKey(): string
    {
        return trim((string) $this->header('Idempotency-Key'));
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'organization' => ['nullable', 'string', 'max:160'],
            'role' => ['nullable', 'string', 'max:100'],
            'contact' => ['required', 'string', 'max:320'],
            'category' => ['required', 'string', 'in:demo,contact,partnership,other'],
            'message' => ['required', 'string', 'max:5000'],
            'consent_version' => ['required', 'string', 'max:64'],
        ];
    }
}
