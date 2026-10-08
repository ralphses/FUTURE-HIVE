<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Database\Factories\AcademicSectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['school_id', 'academic_level_id', 'name', 'code', 'sequence', 'status', 'public_id'])]
#[Hidden(['id'])]
final class AcademicSection extends Model
{
    /** @use HasFactory<AcademicSectionFactory> */
    use HasFactory;

    use TenantScoped;

    protected static function newFactory(): AcademicSectionFactory
    {
        return AcademicSectionFactory::new();
    }

    protected static function booted(): void
    {
        self::creating(function (self $section): void {
            $section->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['sequence' => 'integer'];
    }

    /** @return BelongsTo<AcademicLevel, $this> */
    public function level(): BelongsTo
    {
        return $this->belongsTo(AcademicLevel::class, 'academic_level_id');
    }
}
