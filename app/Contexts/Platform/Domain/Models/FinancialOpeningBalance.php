<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'financial_opening_balance_import_id', 'school_id', 'student_id', 'row_number', 'opening_balance', 'currency', 'as_of_date', 'provenance_reference', 'status', 'error_code'])]
#[Hidden(['id', 'financial_opening_balance_import_id', 'school_id', 'student_id'])]
final class FinancialOpeningBalance extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $balance): void {
            $balance->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['row_number' => 'integer', 'opening_balance' => 'decimal:2', 'as_of_date' => 'date'];
    }

    /** @return BelongsTo<FinancialOpeningBalanceImport, $this> */
    public function import(): BelongsTo
    {
        return $this->belongsTo(FinancialOpeningBalanceImport::class, 'financial_opening_balance_import_id');
    }
}
