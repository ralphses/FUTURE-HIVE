<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PlatformSupportCaseTransitionRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['status' => ['required', 'string', 'in:in_progress,waiting_on_school,resolved,closed,cancelled']];
    }
}
