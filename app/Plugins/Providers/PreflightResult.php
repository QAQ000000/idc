<?php

declare(strict_types=1);

namespace App\Plugins\Providers;

final readonly class PreflightResult
{
    public function __construct(public string $amount, public array $config, public string $currency = 'CNY', public array $context = [], public ?string $recurringAmount = null, public ?StockObservation $stock = null) {}
}
