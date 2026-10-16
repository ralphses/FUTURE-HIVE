<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SchoolAuditExplorerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'action' => ['sometimes', 'string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/'],
            'subject_type' => ['sometimes', 'string', 'max:80', 'regex:/^[A-Za-z0-9._-]+$/'],
            'subject_id' => ['sometimes', 'uuid'],
            'actor' => ['sometimes', 'uuid'],
            'transition' => ['sometimes', 'string', 'max:80', 'regex:/^[A-Za-z0-9._-]+$/'],
            'request_id' => ['sometimes', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
