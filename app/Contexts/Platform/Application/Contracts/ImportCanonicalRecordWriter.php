<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface ImportCanonicalRecordWriter
{
    /** @param array{student_number: string, display_name: string, admission_date?: string|null, metadata?: array<string, mixed>|null} $values */
    public function createStudent(Authenticatable $actor, array $values): string;

    public function verifyCandidate(string $template, string $candidate): string;
}
