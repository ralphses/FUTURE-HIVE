<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RequestContactVerificationRequest extends FormRequest
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
        ];
    }
}
