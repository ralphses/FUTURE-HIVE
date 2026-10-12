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

#[Fillable(['public_id', 'school_id', 'academic_report_card_template_id', 'section_key', 'label', 'sequence', 'is_visible'])]
#[Hidden(['id', 'academic_report_card_template_id'])]
final class AcademicReportCardTemplateSection extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $section): void {
            $section->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'is_visible' => 'boolean'];
    }

    /** @return BelongsTo<AcademicReportCardTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(AcademicReportCardTemplate::class, 'academic_report_card_template_id');
    }

    /** @return HasMany<AcademicReportCardTemplateField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(AcademicReportCardTemplateField::class)->orderBy('sequence');
    }
}
