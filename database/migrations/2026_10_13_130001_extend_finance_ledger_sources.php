<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE financial_ledger_batches DROP CONSTRAINT IF EXISTS financial_ledger_batch_source_allowed');
            DB::statement("ALTER TABLE financial_ledger_batches ADD CONSTRAINT financial_ledger_batch_source_allowed CHECK (source_type IN ('invoice', 'payment_allocation', 'opening_balance', 'adjustment', 'payment_evidence', 'reversal', 'refund'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE financial_ledger_batches DROP CONSTRAINT IF EXISTS financial_ledger_batch_source_allowed');
            DB::statement("ALTER TABLE financial_ledger_batches ADD CONSTRAINT financial_ledger_batch_source_allowed CHECK (source_type IN ('invoice', 'payment_allocation', 'opening_balance', 'adjustment'))");
        }
    }
};
