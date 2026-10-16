<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Infrastructure\Notifications;

use App\Contexts\Platform\Application\Contracts\NotificationDelivery;

final class DeterministicNotificationDelivery implements NotificationDelivery
{
    public function send(string $channel, string $recipientReference, string $deliveryReference): array
    {
        return ['accepted' => true, 'failure_code' => null];
    }
}
