<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Domain\Models;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'academic_session_id', 'academic_term_id', 'academic_subject_offering_id', 'academic_assessment_instance_id', 'opens_at', 'closes_at', 'status', 'created_by', 'activated_by', 'activated_at', 'closed_by', 'closed_at', 'request_id'])]
#[Hidden(['id', 'school_id', 'academic_session_id', 'academic_term_id', 'academic_subject_offering_id', 'academic_assessment_instance_id', 'created_by', 'activated_by', 'closed_by', 'request_id'])]
final class AcademicAssessmentAccessWindow extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $window): void {
            $window->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['opens_at' => 'datetime', 'closes_at' => 'datetime', 'activated_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    /** @return BelongsTo<AcademicAssessmentInstance, $this> */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(AcademicAssessmentInstance::class, 'academic_assessment_instance_id');
    }

    /** @return BelongsTo<UserIdentity, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'created_by');
    }
}
