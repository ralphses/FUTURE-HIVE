<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'import_batch_id', 'import_commit_id', 'school_id', 'template_key', 'template_version', 'status', 'counts', 'error_summary', 'created_by', 'request_id'])]
#[Hidden(['id', 'import_batch_id', 'import_commit_id', 'school_id', 'created_by', 'request_id'])]
final class FinancialOpeningBalanceImport extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $import): void {
            $import->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['counts' => 'array', 'error_summary' => 'array'];
    }

    /** @return HasMany<FinancialOpeningBalance, $this> */
    public function balances(): HasMany
    {
        return $this->hasMany(FinancialOpeningBalance::class);
    }
}
