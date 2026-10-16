<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'user_id', 'legal_policy_id', 'accepted_at', 'request_id'])]
#[Hidden(['id', 'user_id', 'legal_policy_id', 'request_id'])]
final class LegalConsent extends Model
{
    protected static function booted(): void
    {
        self::creating(function (self $consent): void {
            $consent->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }
}
