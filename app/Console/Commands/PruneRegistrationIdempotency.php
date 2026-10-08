<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contexts\Platform\Domain\Models\IdempotencyRecord;
use Illuminate\Console\Command;

final class PruneRegistrationIdempotency extends Command
{
    protected $signature = 'registrations:prune-idempotency';

    protected $description = 'Release idempotency records for terminal school registrations.';

    public function handle(): int
    {
        $deleted = IdempotencyRecord::query()
            ->whereHas('registration', static function ($query): void {
                $query->whereIn('status', ['completed', 'cancelled']);
            })
            ->delete();

        $this->info("Pruned {$deleted} registration idempotency record(s).");

        return self::SUCCESS;
    }
}
