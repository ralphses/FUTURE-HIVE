<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreAiEvaluationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'feature_key' => ['required', 'string', 'in:ADV-001,ADV-002,ADV-003,ADV-004'],
            'fixture_set' => ['required', 'string', 'in:approved_safety_v1,known_failure_safety_v1'],
            'fixture_version' => ['required', 'string', 'max:30'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:120'],
        ];
    }
}
