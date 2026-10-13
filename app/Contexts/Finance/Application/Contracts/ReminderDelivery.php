<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Application\Contracts;

interface ReminderDelivery
{
    /** @return array{accepted: bool, failure_code: string|null} */
    public function send(string $recipientReference, string $reminderReference, int $amountMinorUnits, string $currency): array;
}
