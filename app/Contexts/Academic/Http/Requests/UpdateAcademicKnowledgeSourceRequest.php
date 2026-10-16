<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class UpdateAcademicKnowledgeSourceRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'required', 'string', 'max:180'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'content' => ['sometimes', 'required', 'array', 'max:20'],
            'content.requirements' => ['required_with:content', 'array', 'max:20'],
            'content.requirements.*' => ['required', 'array'],
            'content.requirements.*.label' => ['required', 'string', 'max:160'],
            'content.requirements.*.summary' => ['required', 'string', 'max:500'],
            'content.requirements.*.subjects' => ['nullable', 'array', 'max:20'],
            'content.requirements.*.subjects.*' => ['string', 'max:80'],
            'effective_at' => ['sometimes', 'required', 'date'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:effective_at'],
        ];
    }

    /** @return array<int, Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $content = $this->input('content');
            if (is_array($content) && array_diff(array_keys($content), ['requirements']) !== []) {
                $validator->errors()->add('content', 'The content contains unsupported fields.');
            }
            $requirements = $this->input('content.requirements');
            if (is_array($requirements)) {
                foreach ($requirements as $index => $requirement) {
                    if (is_array($requirement) && array_diff(array_keys($requirement), ['label', 'summary', 'subjects']) !== []) {
                        $validator->errors()->add('content.requirements.'.$index, 'Each requirement contains unsupported fields.');
                    }
                }
            }
        }];
    }
}
