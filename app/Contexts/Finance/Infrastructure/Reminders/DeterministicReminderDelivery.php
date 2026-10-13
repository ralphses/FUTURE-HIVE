<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Infrastructure\Reminders;

use App\Contexts\Finance\Application\Contracts\ReminderDelivery;

final class DeterministicReminderDelivery implements ReminderDelivery
{
    public function send(string $recipientReference, string $reminderReference, int $amountMinorUnits, string $currency): array
    {
        return ['accepted' => true, 'failure_code' => null];
    }
}
