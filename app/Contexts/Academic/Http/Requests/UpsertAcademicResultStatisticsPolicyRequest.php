<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpsertAcademicResultStatisticsPolicyRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'included_scope' => ['required', 'string', 'in:school_term,class_arm,subject_offering'],
            'passing_result_eligibility' => ['required', 'boolean'],
            'tie_handling' => ['required', 'string', 'in:competition,dense,ordinal'],
            'minimum_eligible_population' => ['required', 'integer', 'min:1', 'max:100000'],
            'privacy_suppression_threshold' => ['required', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
