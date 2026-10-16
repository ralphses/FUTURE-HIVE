<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Contracts;

interface AcademicInsightAssistant
{
    /**
     * @param  array<string, mixed>  $context
     * @return array{status: string, confidence: string, observations: list<string>, supporting_indicators: array<string, int>, issue_codes: list<string>, provider_status: string}
     */
    public function suggest(array $context): array;
}
