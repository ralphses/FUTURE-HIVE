<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreCounsellingDevelopmentPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['case_id' => ['required', 'uuid'], 'title' => ['required', 'string', 'max:200'], 'purpose' => ['required', 'string', 'max:2000'], 'goals' => ['required', 'string', 'max:5000'], 'action_items' => ['required', 'string', 'max:5000'], 'review_date' => ['nullable', 'date'], 'progress_state' => ['required', 'string', 'in:not_started,in_progress,completed']];
    }
}
