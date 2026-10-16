<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'development_plan_id', 'scope', 'status', 'created_by', 'request_id'])]
#[Hidden(['id', 'school_id', 'development_plan_id', 'created_by', 'request_id'])]
final class CounsellingDevelopmentPlanShare extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(fn (self $share): string => $share->public_id ??= (string) Str::uuid7());
    }

    /** @return BelongsTo<CounsellingDevelopmentPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(CounsellingDevelopmentPlan::class, 'development_plan_id');
    }
}
