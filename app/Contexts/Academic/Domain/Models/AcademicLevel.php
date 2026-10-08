<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Database\Factories\AcademicLevelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['school_id', 'name', 'code', 'sequence', 'stage', 'status', 'public_id'])]
#[Hidden(['id'])]
final class AcademicLevel extends Model
{
    /** @use HasFactory<AcademicLevelFactory> */
    use HasFactory;

    use TenantScoped;

    protected static function newFactory(): AcademicLevelFactory
    {
        return AcademicLevelFactory::new();
    }

    protected static function booted(): void
    {
        self::creating(function (self $level): void {
            $level->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['sequence' => 'integer'];
    }

    /** @return HasMany<AcademicSection, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(AcademicSection::class);
    }
}
