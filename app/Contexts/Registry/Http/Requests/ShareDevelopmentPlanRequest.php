<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ShareDevelopmentPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['scope' => ['required', 'string', 'in:counselling_staff,safeguarding_leadership']];
    }
}
