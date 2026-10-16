<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Infrastructure\CareerGuidance;

use App\Contexts\Academic\Application\Contracts\CareerGuidanceProvider;
use App\Contexts\Academic\Domain\Models\AcademicKnowledgeSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class DeterministicCareerGuidanceProvider implements CareerGuidanceProvider
{
    /**
     * This local implementation reads only approved, current source projections.
     * It never generates an admissions conclusion or calls an external provider.
     *
     * @param  array{career_interest?: string|null, subject_interest?: string|null, institution_reference?: string|null}  $input
     * @param  array<string, mixed>  $studentContext
     * @return array{status: string, suggestions: list<array<string, mixed>>, requirements: list<array<string, mixed>>, sources: list<array<string, mixed>>, issue_codes: list<string>, provider_status: string}
     */
    public function suggest(array $input, array $studentContext): array
    {
        $sources = AcademicKnowledgeSource::query()
            ->where('status', 'published')
            ->where('effective_at', '<=', now())
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->when($input['institution_reference'] ?? null, fn ($query, string $reference) => $query->where('institution_reference', $reference))
            ->orderBy('source_type')
            ->orderBy('institution_reference')
            ->orderByDesc('version')
            ->orderBy('public_id')
            ->get();

        if ($sources->isNotEmpty()) {
            $requirements = [];
            $sourceData = [];
            foreach ($sources as $source) {
                $requirements = array_merge($requirements, $this->requirements($source));
                $sourceData[] = [
                    'id' => (string) $source->getAttribute('public_id'),
                    'source_type' => (string) $source->getAttribute('source_type'),
                    'institution_reference' => $source->getAttribute('institution_reference'),
                    'label' => (string) $source->getAttribute('label'),
                    'version' => (int) $source->getAttribute('version'),
                    'effective_at' => CarbonImmutable::parse((string) $source->getAttribute('effective_at'))->toISOString(),
                ];
            }

            return [
                'status' => 'available',
                'suggestions' => [],
                'requirements' => $requirements,
                'sources' => $sourceData,
                'issue_codes' => [],
                'provider_status' => 'deterministic_local_approved_source_projection',
            ];
        }

        return [
            'status' => 'unavailable',
            'suggestions' => [],
            'requirements' => [],
            'sources' => [],
            'issue_codes' => ['guidance_unavailable'],
            'provider_status' => 'deterministic_local_no_approved_source',
        ];
    }

    /** @return list<array<string, mixed>> */
    private function requirements(AcademicKnowledgeSource $source): array
    {
        $content = $source->getAttribute('content');
        $requirements = is_array($content) && is_array($content['requirements'] ?? null)
            ? $content['requirements']
            : [];

        return array_map(fn (mixed $requirement): array => [
            'source_id' => (string) $source->public_id,
            'label' => is_array($requirement) ? (string) ($requirement['label'] ?? '') : '',
            'summary' => is_array($requirement) ? (string) ($requirement['summary'] ?? '') : '',
            'subjects' => is_array($requirement) && is_array($requirement['subjects'] ?? null)
                ? array_values(array_map('strval', $requirement['subjects']))
                : [],
        ], $requirements);
    }
}
