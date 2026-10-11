<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateImportMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>|array<string, list<string>>> */
    public function rules(): array
    {
        return [
            'mappings' => ['required', 'array', 'max:64'],
            'mappings.*' => ['required', 'array'],
            'mappings.*.source' => ['required', 'string', 'max:64'],
            'mappings.*.target' => ['required', 'string', 'max:64'],
        ];
    }
}
