<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Infrastructure\LessonPlanning;

use App\Contexts\Academic\Application\Contracts\LessonPlanningAssistant;

final class DeterministicLessonPlanningAssistant implements LessonPlanningAssistant
{
    public function suggest(array $context): array
    {
        $subject = (string) ($context['subject'] ?? 'the selected subject');
        $topic = (string) ($context['topic'] ?? 'the selected topic');
        $focus = (string) ($context['objective_focus'] ?? 'understanding the topic');

        return [
            'objectives' => [
                sprintf('Explain the key ideas in %s using age-appropriate language.', $topic),
                sprintf('Apply %s concepts in a guided classroom activity.', $subject),
                sprintf('Demonstrate learning through %s.', $focus),
            ],
            'activities' => [
                'Begin with a short retrieval prompt connected to prior learning.',
                sprintf('Model one example of %s, then guide paired practice.', $topic),
                'Invite learners to explain their reasoning and compare approaches.',
            ],
            'resources' => ['Approved school scheme of work', 'Teacher-prepared examples', 'Learner exercise materials'],
            'differentiation' => ['Provide a scaffolded example for learners who need support.', 'Offer an extension task that requires transfer to a new example.'],
            'assessment_prompts' => ['What is the main idea?', 'How would you apply it to a new example?', 'What evidence supports your answer?'],
            'provider_status' => 'deterministic_local_suggestion',
        ];
    }
}
