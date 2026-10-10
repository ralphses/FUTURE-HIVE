<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'academic_grading_scale_version_id', 'grade', 'label', 'minimum_percentage', 'maximum_percentage', 'is_passing', 'remark', 'sequence'])]
#[Hidden(['id'])]
final class AcademicGradingBand extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $band): void {
            $band->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['minimum_percentage' => 'decimal:2', 'maximum_percentage' => 'decimal:2', 'is_passing' => 'boolean', 'sequence' => 'integer'];
    }

    /** @return BelongsTo<AcademicGradingScaleVersion, $this> */
    public function scale(): BelongsTo
    {
        return $this->belongsTo(AcademicGradingScaleVersion::class, 'academic_grading_scale_version_id');
    }
}
