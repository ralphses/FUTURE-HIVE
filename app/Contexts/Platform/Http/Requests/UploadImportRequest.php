<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UploadImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'template' => ['required', 'string', 'max:64'],
            'version' => ['required', 'string', 'max:16'],
            'file' => ['required', 'file', 'mimes:csv,xlsx', 'extensions:csv,xlsx', 'max:'.(int) config('imports.max_file_size_kb', 10240)],
        ];
    }
}
