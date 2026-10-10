<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'academic_assessment_policy_version_id', 'version', 'name', 'effective_start', 'effective_end', 'status', 'created_by', 'activated_by', 'activated_at', 'retired_by', 'retired_at'])]
#[Hidden(['id', 'created_by', 'activated_by', 'retired_by'])]
final class AcademicGradingScaleVersion extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $scale): void {
            $scale->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'effective_start' => 'date', 'effective_end' => 'date', 'activated_at' => 'datetime', 'retired_at' => 'datetime'];
    }

    /** @return HasMany<AcademicGradingBand, $this> */
    public function bands(): HasMany
    {
        return $this->hasMany(AcademicGradingBand::class)->orderBy('sequence');
    }

    /** @return BelongsTo<AcademicAssessmentPolicyVersion, $this> */
    public function policyVersion(): BelongsTo
    {
        return $this->belongsTo(AcademicAssessmentPolicyVersion::class, 'academic_assessment_policy_version_id');
    }
}
