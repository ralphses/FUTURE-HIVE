<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class StoreStudentPromotionDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['student_id' => ['required', 'uuid'], 'source_enrollment_id' => ['required', 'uuid'], 'decision' => ['required', 'string'], 'target_level_id' => ['required', 'uuid'], 'target_class_arm_id' => ['required', 'uuid'], 'reason' => ['nullable', 'string', 'max:255']];
    }

    /** @return array<int, callable(Validator): void> */
    protected function after(): array
    {
        return [function (Validator $validator): void {
            if (! in_array($this->input('decision'), ['promote', 'repeat'], true)) {
                $validator->errors()->add('decision', 'Decision must be promote or repeat.');
            }
        }];
    }
}
