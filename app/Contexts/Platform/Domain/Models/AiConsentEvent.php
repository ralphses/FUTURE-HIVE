<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'ai_consent_id', 'user_id', 'event', 'request_id', 'occurred_at'])]
#[Hidden(['id', 'ai_consent_id', 'user_id', 'request_id'])]
final class AiConsentEvent extends Model
{
    protected static function booted(): void
    {
        self::creating(function (self $event): void {
            $event->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
