<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'platform_subscription_plan_id', 'status', 'created_by', 'revoked_by', 'revoked_at', 'request_id'])]
#[Hidden(['id', 'school_id', 'platform_subscription_plan_id', 'created_by', 'revoked_by', 'request_id'])]
final class PlatformSchoolEntitlement extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $entitlement): void {
            $entitlement->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['revoked_at' => 'datetime'];
    }
}
