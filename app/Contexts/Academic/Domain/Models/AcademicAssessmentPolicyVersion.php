<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'academic_session_id', 'academic_term_id', 'academic_subject_offering_id', 'academic_assessment_scheme_id', 'version', 'name', 'total_marks', 'effective_start', 'effective_end', 'status', 'created_by', 'activated_by', 'activated_at', 'retired_by', 'retired_at'])]
#[Hidden(['id', 'created_by', 'activated_by', 'retired_by'])]
final class AcademicAssessmentPolicyVersion extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $version): void {
            $version->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'total_marks' => 'integer',
            'effective_start' => 'date',
            'effective_end' => 'date',
            'activated_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    /** @return HasMany<AcademicAssessmentPolicyComponent, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(AcademicAssessmentPolicyComponent::class);
    }
}
