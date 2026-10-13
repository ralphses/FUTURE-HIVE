<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Application\Contracts;

use App\Contexts\Finance\Domain\Models\FeePaymentIntent;

interface PaymentIntentGateway
{
    /** @return array{next_step: string} */
    public function prepare(FeePaymentIntent $intent): array;
}
