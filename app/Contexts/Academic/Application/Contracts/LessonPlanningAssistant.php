<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Contracts;

interface LessonPlanningAssistant
{
    /** @param array<string, string|null> $context
     * @return array{objectives: list<string>, activities: list<string>, resources: list<string>, differentiation: list<string>, assessment_prompts: list<string>, provider_status: string}
     */
    public function suggest(array $context): array;
}
