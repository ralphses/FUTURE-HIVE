<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** @property list<array{source: string, target: string}> $mappings */
#[Fillable(['public_id', 'import_batch_id', 'school_id', 'template_key', 'template_version', 'mapping_version', 'mappings', 'status', 'created_by', 'request_id'])]
#[Hidden(['id', 'import_batch_id', 'school_id', 'created_by', 'request_id'])]
final class ImportMapping extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $mapping): void {
            $mapping->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return [
            'mapping_version' => 'integer',
            'mappings' => 'array',
        ];
    }

    /** @return BelongsTo<ImportBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }
}
