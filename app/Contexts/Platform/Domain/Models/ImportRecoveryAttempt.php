<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'import_commit_id', 'import_batch_id', 'school_id', 'attempt_number', 'failed_rows_hash', 'validation_snapshot_hash', 'duplicate_snapshot_hash', 'mapping_version', 'status', 'counts', 'error_summary', 'created_by', 'request_id', 'started_at', 'completed_at'])]
#[Hidden(['id', 'import_commit_id', 'import_batch_id', 'school_id', 'failed_rows_hash', 'validation_snapshot_hash', 'duplicate_snapshot_hash', 'created_by', 'request_id'])]
final class ImportRecoveryAttempt extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $attempt): void {
            $attempt->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['attempt_number' => 'integer', 'mapping_version' => 'integer', 'counts' => 'array', 'error_summary' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    /** @return BelongsTo<ImportCommit, $this> */
    public function commit(): BelongsTo
    {
        return $this->belongsTo(ImportCommit::class, 'import_commit_id');
    }

    /** @return HasMany<ImportRecoveryRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportRecoveryRow::class, 'import_recovery_attempt_id');
    }
}
