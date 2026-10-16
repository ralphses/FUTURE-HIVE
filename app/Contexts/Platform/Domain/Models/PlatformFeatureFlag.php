<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'flag_key', 'label', 'description', 'value_type', 'value', 'scope', 'school_id', 'version', 'status', 'created_by', 'activated_by', 'activated_at', 'retired_by', 'retired_at', 'request_id'])]
#[Hidden(['id', 'school_id', 'created_by', 'activated_by', 'retired_by', 'request_id'])]
final class PlatformFeatureFlag extends Model
{
    protected static function booted(): void
    {
        self::creating(function (self $flag): void {
            $flag->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return [
            'value' => 'json',
            'version' => 'integer',
            'school_id' => 'integer',
            'activated_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }
}
