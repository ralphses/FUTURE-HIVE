<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateSchoolRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function idempotencyKey(): string
    {
        return trim((string) $this->header('Idempotency-Key'));
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'school_name' => ['required', 'string', 'max:160'],
            'school_type' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'max:100'],
            'contact' => ['required', 'string', 'max:320'],
            'consent_version' => ['required', 'string', 'max:64'],
        ];
    }
}
