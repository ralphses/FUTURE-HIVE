<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Contexts\Platform\Domain\Models\PublicMarketingPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PublicMarketingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_readers_receive_only_published_pages_in_deterministic_order(): void
    {
        PublicMarketingPage::query()->create(['slug' => 'faq', 'category' => 'faq', 'title' => 'Frequently asked questions', 'summary' => 'Answers', 'sections' => [['heading' => 'Question', 'body' => 'Answer']], 'version' => 1, 'status' => 'published', 'published_at' => now()]);
        PublicMarketingPage::query()->create(['slug' => 'benefits', 'category' => 'benefits', 'title' => 'Benefits', 'summary' => 'Product benefits', 'sections' => [], 'version' => 1, 'status' => 'published', 'published_at' => now()]);
        PublicMarketingPage::query()->create(['slug' => 'hidden', 'category' => 'product', 'title' => 'Hidden', 'summary' => 'Do not show', 'sections' => [], 'version' => 1, 'status' => 'draft']);

        $this->getJson('/api/v1/public/marketing-pages')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonFragment(['slug' => 'benefits'])->assertJsonFragment(['slug' => 'faq'])->assertJsonMissing(['slug' => 'hidden']);

        $page = PublicMarketingPage::query()->where('slug', 'faq')->firstOrFail();
        $this->getJson('/api/v1/public/marketing-pages/'.$page->public_id)->assertOk()->assertJsonPath('data.page.sections.0.body', 'Answer');
    }

    public function test_unknown_or_unpublished_pages_are_unavailable_without_authentication(): void
    {
        $page = PublicMarketingPage::query()->create(['slug' => 'draft', 'category' => 'product', 'title' => 'Draft', 'sections' => [], 'version' => 1, 'status' => 'draft']);

        $this->getJson('/api/v1/public/marketing-pages/'.$page->public_id)->assertNotFound();
        $this->getJson('/api/v1/public/marketing-pages/not-a-real-page')->assertNotFound();
    }
}
