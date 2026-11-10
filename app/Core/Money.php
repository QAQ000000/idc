<?php

declare(strict_types=1);

namespace App\Core;

final class Money
{
    public static function markup(string $cost, string $type, string $value): string
    {
        $cost = self::positive($cost);
        if (! preg_match('/^(0|[1-9][0-9]{0,7})(\.[0-9]{1,2})?$/D', $value)) {
            throw new ApiException('Invalid markup');
        }
        $amount = match ($type) {
            'none' => $cost,
            'fixed' => bcadd($cost, $value, 6),
            'percent' => bcadd($cost, bcdiv(bcmul($cost, $value, 6), '100', 6), 6),
            default => throw new ApiException('Invalid markup type'),
        };

        return self::positive(bcadd($amount, '0.005', 2));
    }

    public static function positive(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^(0|[1-9][0-9]{0,7})(\.[0-9]{1,2})?$/D', $value)
            || bccomp($value, '0', 2) <= 0) {
            throw new ApiException('Amount must be a positive decimal string (maximum 99999999.99)');
        }

        return bcadd($value, '0', 2);
    }

    public static function add(string $before, string $delta): string
    {
        $after = bcadd($before, $delta, 2);
        if (bccomp($after, '99999999.99', 2) > 0) {
            throw new ApiException('Amount exceeds account limit');
        }

        return $after;
    }
}
