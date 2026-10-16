<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreCounsellingCaseNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['note_type' => ['required', 'string', 'in:session,observation,follow_up'], 'content' => ['required', 'string', 'max:10000']];
    }
}
