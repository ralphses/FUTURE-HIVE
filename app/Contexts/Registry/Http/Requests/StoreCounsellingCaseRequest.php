<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreCounsellingCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['assignment_id' => ['required', 'uuid'], 'title' => ['required', 'string', 'max:200'], 'category' => ['required', 'string', 'max:80'], 'reason' => ['nullable', 'string', 'max:500']];
    }
}
