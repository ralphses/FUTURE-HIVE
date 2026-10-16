<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'slug', 'category', 'title', 'summary', 'sections', 'version', 'status', 'published_at'])]
#[Hidden(['id'])]
final class PublicMarketingPage extends Model
{
    protected static function booted(): void
    {
        self::creating(function (self $page): void {
            $page->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return [
            'sections' => 'array',
            'version' => 'integer',
            'published_at' => 'datetime',
        ];
    }
}
