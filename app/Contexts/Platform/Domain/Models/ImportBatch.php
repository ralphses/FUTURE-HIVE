<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'template_key', 'template_version', 'original_filename', 'format', 'size', 'checksum', 'uploaded_by', 'status', 'row_count', 'failure_reason', 'request_id'])]
#[Hidden(['id', 'school_id', 'checksum', 'uploaded_by', 'failure_reason', 'request_id'])]
final class ImportBatch extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $batch): void {
            $batch->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['size' => 'integer', 'row_count' => 'integer'];
    }

    /** @return HasOne<ImportBatchFile, $this> */
    public function file(): HasOne
    {
        return $this->hasOne(ImportBatchFile::class);
    }

    /** @return HasMany<ImportStagedRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportStagedRow::class);
    }
}
