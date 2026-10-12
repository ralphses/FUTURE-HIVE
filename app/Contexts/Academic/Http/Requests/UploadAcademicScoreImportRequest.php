<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UploadAcademicScoreImportRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['file' => ['required', 'file', 'mimes:csv,xlsx', 'extensions:csv,xlsx', 'max:'.(int) config('imports.max_file_size_kb', 10240)]];
    }
}
