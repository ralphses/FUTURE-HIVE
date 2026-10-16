<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Contracts;

interface NotificationDelivery
{
    /** @return array{accepted: bool, failure_code: string|null} */
    public function send(string $channel, string $recipientReference, string $deliveryReference): array;
}
