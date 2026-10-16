<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'subject', 'category', 'severity', 'status', 'reason_code', 'created_by', 'updated_by', 'closed_by', 'closed_at', 'request_id'])]
#[Hidden(['id', 'school_id', 'created_by', 'updated_by', 'closed_by', 'request_id'])]
final class PlatformSupportCase extends Model
{
    protected static function booted(): void
    {
        self::creating(function (self $case): void {
            $case->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['closed_at' => 'datetime'];
    }
}
