<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AssignSchoolRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'roles' => ['required', 'array', 'min:1', 'max:9'],
            'roles.*' => ['required', 'string', 'distinct', 'regex:/^[a-z][a-z0-9_]{1,79}$/'],
        ];
    }
}
