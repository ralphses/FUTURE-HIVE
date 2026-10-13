<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'fee_invoice_id', 'fee_plan_line_id', 'fee_category_id', 'category_code', 'category_name', 'amount_minor_units', 'sequence', 'is_required'])]
#[Hidden(['id', 'school_id', 'fee_invoice_id', 'fee_plan_line_id', 'fee_category_id'])]
final class FeeInvoiceLine extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $line): void {
            $line->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['amount_minor_units' => 'integer', 'sequence' => 'integer', 'is_required' => 'boolean'];
    }

    /** @return BelongsTo<FeeInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FeeInvoice::class, 'fee_invoice_id');
    }
}
