<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'academic_report_card_template_section_id', 'field_key', 'label', 'field_type', 'sequence', 'is_visible'])]
#[Hidden(['id', 'academic_report_card_template_section_id'])]
final class AcademicReportCardTemplateField extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $field): void {
            $field->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'is_visible' => 'boolean'];
    }

    /** @return BelongsTo<AcademicReportCardTemplateSection, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(AcademicReportCardTemplateSection::class, 'academic_report_card_template_section_id');
    }
}
