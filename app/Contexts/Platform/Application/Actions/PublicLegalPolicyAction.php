<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

use App\Contexts\Platform\Domain\Models\LegalPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;

final class PublicLegalPolicyAction
{
    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function index(int $page = 1, int $perPage = 25): LengthAwarePaginator
    {
        return LegalPolicy::query()
            ->where('status', 'published')
            ->orderBy('policy_type')
            ->orderByDesc('version')
            ->orderBy('public_id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->through(fn (LegalPolicy $policy): array => $this->data($policy));
    }

    /** @return array{policy: array<string, mixed>} */
    public function show(string $policyId): array
    {
        $policy = LegalPolicy::query()
            ->where('public_id', $policyId)
            ->where('status', 'published')
            ->first();

        if ($policy === null) {
            throw new ModelNotFoundException;
        }

        return ['policy' => $this->data($policy, true)];
    }

    /** @return array<string, mixed> */
    private function data(LegalPolicy $policy, bool $includeSections = false): array
    {
        $data = [
            'id' => (string) $policy->public_id,
            'policy_type' => (string) $policy->policy_type,
            'title' => (string) $policy->title,
            'version' => (int) $policy->version,
            'status' => 'published',
            'effective_at' => $this->timestamp($policy->effective_at),
            'published_at' => $this->timestamp($policy->published_at),
        ];

        if ($includeSections) {
            $data['sections'] = $this->sections($policy->sections);
        }

        return $data;
    }

    /** @return list<array{heading: string, body: string}> */
    private function sections(mixed $sections): array
    {
        if (! is_array($sections)) {
            return [];
        }

        $safe = [];
        foreach ($sections as $section) {
            if (is_array($section)) {
                $safe[] = [
                    'heading' => is_scalar($section['heading'] ?? null) ? (string) $section['heading'] : '',
                    'body' => is_scalar($section['body'] ?? null) ? (string) $section['body'] : '',
                ];
            }
        }

        return $safe;
    }

    private function timestamp(mixed $timestamp): ?string
    {
        return $timestamp === null ? null : CarbonImmutable::parse((string) $timestamp)->toISOString();
    }
}
