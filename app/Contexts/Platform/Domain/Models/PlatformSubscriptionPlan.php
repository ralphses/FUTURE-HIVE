<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'code', 'name', 'description', 'version', 'entitlement_keys', 'status', 'created_by', 'activated_by', 'activated_at', 'retired_by', 'retired_at', 'request_id'])]
#[Hidden(['id', 'created_by', 'activated_by', 'retired_by', 'request_id'])]
final class PlatformSubscriptionPlan extends Model
{
    protected static function booted(): void
    {
        self::creating(function (self $plan): void {
            $plan->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'entitlement_keys' => 'array',
            'activated_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }
}
