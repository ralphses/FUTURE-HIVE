<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateGuardianRelationshipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'relationship_type' => ['required', 'string', 'in:parent,guardian,grandparent,foster_parent,other'],
            'display_name' => ['nullable', 'string', 'max:160'],
            'metadata' => ['nullable', 'array', 'max:10'],
        ];
    }
}
