<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ResolveImportDuplicateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'resolution' => ['required', 'string', 'in:create_new,link_existing,skip'],
            'candidate_public_id' => ['nullable', 'uuid'],
        ];
    }
}
