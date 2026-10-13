<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Infrastructure\Payments;

use App\Contexts\Finance\Application\Contracts\PaymentIntentGateway;
use App\Contexts\Finance\Domain\Models\FeePaymentIntent;

final class DeterministicPaymentIntentGateway implements PaymentIntentGateway
{
    /** @return array{next_step: string} */
    public function prepare(FeePaymentIntent $intent): array
    {
        return ['next_step' => 'awaiting_provider_confirmation'];
    }
}
