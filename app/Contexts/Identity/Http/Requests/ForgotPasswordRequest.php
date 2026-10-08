<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ForgotPasswordRequest extends FormRequest
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
        ];
    }
}
