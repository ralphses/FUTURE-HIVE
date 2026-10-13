<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Domain\Models;

use App\Contexts\Academic\Domain\Models\AcademicSession;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'academic_session_id', 'academic_term_id', 'fee_plan_version_id', 'due_date', 'status', 'generated_by', 'finalized_by', 'finalized_at', 'cancelled_by', 'cancelled_at', 'request_id'])]
#[Hidden(['id', 'school_id', 'generated_by', 'finalized_by', 'cancelled_by', 'request_id'])]
final class FeeAssessmentRun extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $run): void {
            $run->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['due_date' => 'date', 'finalized_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    /** @return BelongsTo<AcademicSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'academic_session_id');
    }

    /** @return BelongsTo<AcademicTerm, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class, 'academic_term_id');
    }

    /** @return BelongsTo<FeePlanVersion, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(FeePlanVersion::class, 'fee_plan_version_id');
    }

    /** @return HasMany<FeeAssessmentItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(FeeAssessmentItem::class);
    }

    /** @return HasMany<FeeInvoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(FeeInvoice::class);
    }

    /** @return BelongsTo<UserIdentity, $this> */
    public function generator(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'generated_by');
    }
}
