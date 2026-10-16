<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateCounsellingDevelopmentPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['title' => ['sometimes', 'required', 'string', 'max:200'], 'purpose' => ['sometimes', 'required', 'string', 'max:2000'], 'goals' => ['sometimes', 'required', 'string', 'max:5000'], 'action_items' => ['sometimes', 'required', 'string', 'max:5000'], 'review_date' => ['nullable', 'date'], 'progress_state' => ['sometimes', 'required', 'string', 'in:not_started,in_progress,completed']];
    }
}
