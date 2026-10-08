<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Database\Factories\AcademicClassArmFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['school_id', 'academic_level_id', 'academic_section_id', 'name', 'code', 'capacity', 'status', 'public_id'])]
#[Hidden(['id'])]
final class AcademicClassArm extends Model
{
    /** @use HasFactory<AcademicClassArmFactory> */
    use HasFactory;

    use TenantScoped;

    protected static function newFactory(): AcademicClassArmFactory
    {
        return AcademicClassArmFactory::new();
    }

    protected static function booted(): void
    {
        self::creating(function (self $classArm): void {
            $classArm->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['capacity' => 'integer'];
    }

    /** @return BelongsTo<AcademicLevel, $this> */
    public function level(): BelongsTo
    {
        return $this->belongsTo(AcademicLevel::class, 'academic_level_id');
    }

    /** @return BelongsTo<AcademicSection, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(AcademicSection::class, 'academic_section_id');
    }
}
