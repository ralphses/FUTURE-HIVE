<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Contracts;

interface CareerGuidanceProvider
{
    /**
     * @param  array{career_interest?: string|null, subject_interest?: string|null, institution_reference?: string|null}  $input
     * @param  array<string, mixed>  $studentContext
     * @return array{status: string, suggestions: list<array<string, mixed>>, requirements: list<array<string, mixed>>, sources: list<array<string, mixed>>, issue_codes: list<string>, provider_status: string}
     */
    public function suggest(array $input, array $studentContext): array;
}
