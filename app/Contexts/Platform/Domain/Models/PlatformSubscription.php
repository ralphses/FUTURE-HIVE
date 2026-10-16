<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'platform_subscription_plan_id', 'status', 'starts_at', 'trial_ends_at', 'ends_at', 'grace_starts_at', 'grace_ends_at', 'cancelled_at', 'created_by', 'cancelled_by', 'termination_reason', 'request_id'])]
#[Hidden(['id', 'school_id', 'platform_subscription_plan_id', 'created_by', 'cancelled_by', 'request_id'])]
final class PlatformSubscription extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $subscription): void {
            $subscription->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'ends_at' => 'datetime',
            'grace_starts_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
