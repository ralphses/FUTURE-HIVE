<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PutAcademicAssessmentAccessWindowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'opens_at' => ['required', 'date_format:Y-m-d\\TH:i:sP'],
            'closes_at' => ['required', 'date_format:Y-m-d\\TH:i:sP'],
        ];
    }
}
