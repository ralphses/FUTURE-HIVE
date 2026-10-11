<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['import_batch_id', 'school_id', 'cloudinary_public_id', 'cloudinary_resource_type', 'format'])]
#[Hidden(['id', 'school_id', 'import_batch_id', 'cloudinary_public_id', 'cloudinary_resource_type'])]
final class ImportBatchFile extends Model
{
    use TenantScoped;

    public $timestamps = false;

    /** @return BelongsTo<ImportBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }
}
