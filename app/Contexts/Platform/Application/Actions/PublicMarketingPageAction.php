<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

use App\Contexts\Platform\Domain\Models\PublicMarketingPage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;

final class PublicMarketingPageAction
{
    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function index(int $page = 1, int $perPage = 25, ?string $category = null): LengthAwarePaginator
    {
        $query = PublicMarketingPage::query()
            ->where('status', 'published')
            ->orderBy('category')
            ->orderBy('slug')
            ->orderByDesc('version');

        if ($category !== null) {
            $query->where('category', $category);
        }

        return $query->paginate($perPage, ['*'], 'page', $page)->through(fn (PublicMarketingPage $page): array => $this->data($page));
    }

    /** @return array{page: array<string, mixed>} */
    public function show(string $pageId): array
    {
        $page = PublicMarketingPage::query()->where('public_id', $pageId)->where('status', 'published')->first();
        if ($page === null) {
            throw new ModelNotFoundException;
        }

        return ['page' => $this->data($page, true)];
    }

    /** @return array<string, mixed> */
    private function data(PublicMarketingPage $page, bool $includeSections = false): array
    {
        $data = [
            'id' => (string) $page->public_id,
            'slug' => (string) $page->slug,
            'category' => (string) $page->category,
            'title' => (string) $page->title,
            'summary' => $page->summary,
            'version' => (int) $page->version,
            'status' => 'published',
            'published_at' => $page->published_at === null ? null : CarbonImmutable::parse((string) $page->published_at)->toISOString(),
        ];

        if ($includeSections) {
            $data['sections'] = $this->sections($page->getAttribute('sections'));
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
}
