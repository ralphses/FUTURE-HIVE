<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Requests;

use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class UpdateSchoolProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'contact' => ['sometimes', 'nullable', 'string', 'max:191'],
            'address_line1' => ['sometimes', 'nullable', 'string', 'max:160'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:160'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'state' => ['sometimes', 'nullable', 'string', 'max:100'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'timezone' => ['sometimes', 'string', 'max:64'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('timezone') || ! $this->has('timezone')) {
                return;
            }

            try {
                new DateTimeZone($this->string('timezone')->toString());
            } catch (\Exception) {
                $validator->errors()->add('timezone', 'The timezone must be a valid IANA timezone.');
            }
        }];
    }
}
