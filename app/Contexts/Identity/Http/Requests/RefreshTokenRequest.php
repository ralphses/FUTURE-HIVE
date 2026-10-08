<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RefreshTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'refresh_token' => ['sometimes', 'nullable', 'string', 'min:64', 'max:200'],
            'client' => ['sometimes', 'string', 'in:api,browser'],
        ];
    }
}
