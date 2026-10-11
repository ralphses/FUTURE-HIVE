<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property array<string, string> $cells
 * @property array<string, string>|null $normalized_cells
 * @property list<array{field: string, code: string, message: string}>|null $error_metadata
 */
#[Fillable(['import_batch_id', 'school_id', 'row_number', 'cells', 'normalized_cells', 'status', 'error_metadata'])]
#[Hidden(['id', 'school_id', 'import_batch_id', 'cells', 'normalized_cells', 'error_metadata'])]
final class ImportStagedRow extends Model
{
    use TenantScoped;

    protected function casts(): array
    {
        return ['row_number' => 'integer', 'cells' => 'array', 'normalized_cells' => 'array', 'error_metadata' => 'array'];
    }

    /** @return BelongsTo<ImportBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }
}
