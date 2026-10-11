<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateTeachingAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['title' => ['sometimes', 'required', 'string', 'max:180'], 'body' => ['sometimes', 'required', 'string', 'max:20000']];
    }
}
