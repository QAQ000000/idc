<?php
declare(strict_types=1);
namespace App\Core;

final class Money
{
    public static function positive(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]{0,7})(\.[0-9]{1,2})?$/D', $value)
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
