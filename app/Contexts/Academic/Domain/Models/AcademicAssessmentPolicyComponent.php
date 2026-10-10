<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'academic_assessment_policy_version_id', 'name', 'category', 'max_marks', 'sequence'])]
#[Hidden(['id'])]
final class AcademicAssessmentPolicyComponent extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $component): void {
            $component->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['max_marks' => 'integer', 'sequence' => 'integer'];
    }

    /** @return BelongsTo<AcademicAssessmentPolicyVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(AcademicAssessmentPolicyVersion::class, 'academic_assessment_policy_version_id');
    }
}
