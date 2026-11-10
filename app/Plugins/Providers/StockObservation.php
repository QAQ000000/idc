<?php
declare(strict_types=1);
namespace App\Plugins\Providers;

/** Display-only observation. Never a reservation or part of the quote identity. */
final readonly class StockObservation
{
    public function __construct(public string $state, public ?int $quantity = null)
    {
        if (!in_array($state, ['in_stock','sold_out','unlimited','unknown'], true)
            || ($quantity !== null && ($quantity < 0 || !in_array($state,['in_stock','sold_out'],true)))
            || ($state === 'sold_out' && $quantity !== 0)
            || ($state === 'in_stock' && $quantity !== null && $quantity < 1)) {
            throw new \InvalidArgumentException('Invalid stock observation');
        }
    }
}
