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

#[Fillable(['public_id', 'import_batch_id', 'school_id', 'template_key', 'template_version', 'mapping_version', 'validation_snapshot_hash', 'duplicate_snapshot_hash', 'dry_run_report_id', 'status', 'counts', 'error_summary', 'created_by', 'request_id', 'started_at', 'completed_at'])]
#[Hidden(['id', 'import_batch_id', 'school_id', 'validation_snapshot_hash', 'duplicate_snapshot_hash', 'dry_run_report_id', 'created_by', 'request_id'])]
final class ImportCommit extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $commit): void {
            $commit->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['mapping_version' => 'integer', 'counts' => 'array', 'error_summary' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    /** @return HasMany<ImportCommitRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportCommitRow::class);
    }

    /** @return BelongsTo<ImportBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }
}
