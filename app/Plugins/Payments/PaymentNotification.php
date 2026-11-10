<?php

declare(strict_types=1);

namespace App\Plugins\Payments;

final readonly class PaymentNotification
{
    public function __construct(public string $merchantOrder, public string $gatewayTrade, public string $amount, public string $currency = 'CNY') {}
}
