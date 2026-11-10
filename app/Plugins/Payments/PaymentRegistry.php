<?php

declare(strict_types=1);

namespace App\Plugins\Payments;

use App\Plugins\PluginLoader;

final class PaymentRegistry
{
    public function __construct(private readonly array $factories) {}

    public static function installed(): self
    {
        return new self(PluginLoader::load(dirname(__DIR__, 3).'/plugins/payments', 'payment', PaymentGatewayInterface::class));
    }

    public function get(string $id): PaymentGatewayInterface
    {
        $driver = isset($this->factories[$id]) ? ($this->factories[$id])() : null;
        if (! $driver instanceof PaymentGatewayInterface || $driver->getDriverId() !== $id) {
            throw new \RuntimeException('Payment gateway unavailable');
        }

        return $driver;
    }
}
