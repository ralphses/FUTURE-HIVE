<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Database\Factories\Contexts\Academic\Domain\Models\AcademicSchemeTopicFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'academic_scheme_of_work_id', 'title', 'description', 'sequence', 'teaching_week', 'status', 'completed_at', 'updated_by'])]
#[Hidden(['id', 'updated_by'])]
final class AcademicSchemeTopic extends Model
{
    /** @use HasFactory<AcademicSchemeTopicFactory> */
    use HasFactory, TenantScoped;

    protected static function newFactory(): AcademicSchemeTopicFactory
    {
        return AcademicSchemeTopicFactory::new();
    }

    protected static function booted(): void
    {
        self::creating(function (self $topic): void {
            $topic->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'teaching_week' => 'integer', 'completed_at' => 'datetime'];
    }

    /** @return BelongsTo<AcademicSchemeOfWork, $this> */
    public function scheme(): BelongsTo
    {
        return $this->belongsTo(AcademicSchemeOfWork::class, 'academic_scheme_of_work_id');
    }
}
