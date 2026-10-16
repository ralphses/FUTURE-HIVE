<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ReviewAiEvaluationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', 'in:approved,rejected'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
