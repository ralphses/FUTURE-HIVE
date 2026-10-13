<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Domain\Models;

use Illuminate\Database\Eloquent\Model;

final class FinanceReceiptSequence extends Model
{
    protected $table = 'finance_receipt_sequences';

    public $timestamps = false;

    protected $fillable = ['school_id', 'next_number'];

    protected function casts(): array
    {
        return ['next_number' => 'integer'];
    }
}
