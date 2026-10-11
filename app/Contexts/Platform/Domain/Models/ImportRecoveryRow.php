<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['import_recovery_attempt_id', 'import_commit_row_id', 'school_id', 'row_number', 'record_type', 'action', 'canonical_public_id', 'status', 'error_code'])]
#[Hidden(['id', 'import_recovery_attempt_id', 'import_commit_row_id', 'school_id'])]
final class ImportRecoveryRow extends Model
{
    use TenantScoped;

    protected function casts(): array
    {
        return ['row_number' => 'integer'];
    }

    /** @return BelongsTo<ImportRecoveryAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ImportRecoveryAttempt::class, 'import_recovery_attempt_id');
    }
}
