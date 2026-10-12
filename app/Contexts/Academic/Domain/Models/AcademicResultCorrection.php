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

#[Fillable(['public_id', 'school_id', 'academic_session_id', 'academic_term_id', 'academic_result_snapshot_id', 'source_result_run_id', 'source_fingerprint', 'status', 'reason', 'requested_by', 'approved_by', 'rejected_by', 'applied_by', 'superseding_result_run_id', 'superseding_snapshot_id', 'approved_at', 'rejected_at', 'applied_at', 'request_id'])]
#[Hidden(['id', 'school_id', 'academic_session_id', 'academic_term_id', 'academic_result_snapshot_id', 'source_result_run_id', 'source_fingerprint', 'requested_by', 'approved_by', 'rejected_by', 'applied_by', 'request_id'])]
final class AcademicResultCorrection extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $correction): void {
            $correction->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'rejected_at' => 'datetime', 'applied_at' => 'datetime'];
    }

    /** @return BelongsTo<AcademicResultSnapshot, $this> */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(AcademicResultSnapshot::class, 'academic_result_snapshot_id');
    }

    /** @return BelongsTo<AcademicResultRun, $this> */
    public function sourceRun(): BelongsTo
    {
        return $this->belongsTo(AcademicResultRun::class, 'source_result_run_id');
    }

    /** @return BelongsTo<AcademicResultRun, $this> */
    public function supersedingRun(): BelongsTo
    {
        return $this->belongsTo(AcademicResultRun::class, 'superseding_result_run_id');
    }

    /** @return BelongsTo<AcademicResultSnapshot, $this> */
    public function supersedingSnapshot(): BelongsTo
    {
        return $this->belongsTo(AcademicResultSnapshot::class, 'superseding_snapshot_id');
    }

    /** @return BelongsTo<UserIdentity, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'requested_by');
    }
}
