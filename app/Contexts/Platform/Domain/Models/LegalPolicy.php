<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'policy_type', 'title', 'sections', 'version', 'status', 'effective_at', 'published_at', 'created_by', 'request_id'])]
#[Hidden(['id', 'created_by', 'request_id'])]
final class LegalPolicy extends Model
{
    protected static function booted(): void
    {
        self::creating(function (self $policy): void {
            $policy->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return [
            'sections' => 'array',
            'version' => 'integer',
            'effective_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
