<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'import_batch_id', 'import_staged_row_id', 'school_id', 'candidate_type', 'candidate_public_id', 'candidate_row_number', 'detection_source', 'match_key_hash', 'reason_code', 'resolution_state', 'resolved_by', 'resolved_at', 'request_id'])]
#[Hidden(['id', 'school_id', 'import_batch_id', 'import_staged_row_id', 'match_key_hash', 'resolved_by', 'request_id'])]
final class ImportDuplicateFinding extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $finding): void {
            $finding->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['candidate_row_number' => 'integer', 'resolved_at' => 'datetime'];
    }

    /** @return BelongsTo<ImportBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    /** @return BelongsTo<ImportStagedRow, $this> */
    public function row(): BelongsTo
    {
        return $this->belongsTo(ImportStagedRow::class, 'import_staged_row_id');
    }
}
