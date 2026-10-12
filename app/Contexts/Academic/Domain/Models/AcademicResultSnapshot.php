<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Domain\Models;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'academic_session_id', 'academic_term_id', 'academic_result_gate_id', 'academic_result_run_id', 'supersedes_snapshot_id', 'source_fingerprint', 'snapshot_version', 'status', 'policy_references', 'grading_scale_references', 'published_by', 'published_at', 'request_id'])]
#[Hidden(['id', 'school_id', 'academic_session_id', 'academic_term_id', 'academic_result_gate_id', 'academic_result_run_id', 'published_by', 'request_id', 'source_fingerprint'])]
final class AcademicResultSnapshot extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $snapshot): void {
            $snapshot->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['policy_references' => 'array', 'grading_scale_references' => 'array', 'snapshot_version' => 'integer', 'published_at' => 'datetime'];
    }

    /** @return BelongsTo<AcademicResultRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AcademicResultRun::class, 'academic_result_run_id');
    }

    /** @return BelongsTo<AcademicResultGate, $this> */
    public function gate(): BelongsTo
    {
        return $this->belongsTo(AcademicResultGate::class, 'academic_result_gate_id');
    }

    /** @return BelongsTo<self, $this> */
    public function supersededSnapshot(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_snapshot_id');
    }

    /** @return HasMany<self, $this> */
    public function supersedingSnapshots(): HasMany
    {
        return $this->hasMany(self::class, 'supersedes_snapshot_id');
    }

    /** @return BelongsTo<UserIdentity, $this> */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'published_by');
    }

    /** @return HasMany<AcademicResultSnapshotEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(AcademicResultSnapshotEntry::class);
    }
}
