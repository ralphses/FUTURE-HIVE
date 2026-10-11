<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['import_commit_id', 'import_staged_row_id', 'school_id', 'row_number', 'record_type', 'action', 'canonical_public_id', 'status', 'error_code'])]
#[Hidden(['id', 'import_commit_id', 'import_staged_row_id', 'school_id'])]
final class ImportCommitRow extends Model
{
    use TenantScoped;

    protected function casts(): array
    {
        return ['row_number' => 'integer'];
    }

    /** @return BelongsTo<ImportCommit, $this> */
    public function commit(): BelongsTo
    {
        return $this->belongsTo(ImportCommit::class, 'import_commit_id');
    }
}
