<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Infrastructure\Insights;

use App\Contexts\Academic\Application\Contracts\AcademicInsightAssistant;

final class DeterministicAcademicInsightAssistant implements AcademicInsightAssistant
{
    /** @param array<string, mixed> $context */
    public function suggest(array $context): array
    {
        $indicators = [
            'published_result_count' => (int) ($context['published_result_count'] ?? 0),
            'attendance_register_count' => (int) ($context['attendance_register_count'] ?? 0),
            'attendance_recorded_count' => (int) ($context['attendance_recorded_count'] ?? 0),
            'assessment_submission_count' => (int) ($context['assessment_submission_count'] ?? 0),
        ];
        $available = array_sum($indicators) > 0;
        $observations = [];

        if ($indicators['published_result_count'] > 0) {
            $observations[] = 'Published academic result data is available for review.';
        }
        if ($indicators['attendance_register_count'] > 0) {
            $observations[] = 'Submitted or locked attendance data is available for review.';
        }
        if ($indicators['assessment_submission_count'] > 0) {
            $observations[] = 'Assessment submission activity is available for review.';
        }

        return [
            'status' => $available ? 'suggested_unreviewed' : 'not_available',
            'confidence' => $available ? 'bounded' : 'unavailable',
            'observations' => $observations,
            'supporting_indicators' => $indicators,
            'issue_codes' => $available ? [] : ['academic_insight_context_unavailable'],
            'provider_status' => 'deterministic_local_suggestion',
        ];
    }
}
