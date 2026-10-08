<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ConfirmContactVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'contact' => ['required', 'string', 'max:320'],
            'code' => ['required', 'string', 'size:6', 'regex:/^[0-9]{6}$/'],
        ];
    }
}
