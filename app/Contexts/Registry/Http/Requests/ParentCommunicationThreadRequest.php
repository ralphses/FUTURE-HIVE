<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ParentCommunicationThreadRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['student' => ['required', 'string', 'max:64'], 'title' => ['required', 'string', 'min:1', 'max:160'], 'body' => ['required', 'string', 'min:1', 'max:4000']];
    }
}
