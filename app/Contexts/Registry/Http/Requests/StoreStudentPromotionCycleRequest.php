<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreStudentPromotionCycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['source_term_id' => ['required', 'uuid'], 'target_session_id' => ['required', 'uuid'], 'target_term_id' => ['required', 'uuid'], 'promotion_rule_id' => ['nullable', 'uuid'], 'reason' => ['nullable', 'string', 'max:255']];
    }
}
