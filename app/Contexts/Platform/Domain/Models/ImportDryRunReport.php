<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property string $public_id
 * @property array<string, mixed> $report
 */
#[Fillable(['public_id', 'import_batch_id', 'school_id', 'template_key', 'template_version', 'mapping_version', 'validation_snapshot_hash', 'duplicate_snapshot_hash', 'status', 'report', 'generated_by', 'request_id'])]
#[Hidden(['id', 'import_batch_id', 'school_id', 'validation_snapshot_hash', 'duplicate_snapshot_hash', 'generated_by', 'request_id'])]
final class ImportDryRunReport extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $report): void {
            $report->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['mapping_version' => 'integer', 'report' => 'array'];
    }

    /** @return BelongsTo<ImportBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }
}
