<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateNotificationTemplateRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['title' => ['sometimes', 'string', 'max:160'], 'body' => ['sometimes', 'string', 'max:4000']];
    }
}
