<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'max:320'],
            'code' => ['required', 'string', 'digits:6'],
            'password' => ['required', 'string', 'min:12', 'max:128', 'confirmed'],
        ];
    }
}
