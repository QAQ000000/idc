<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\RedisStore;
use App\Plugins\Providers\StockObservation;

/** Disposable cache only: no MySQL product quota, snapshot or task writes. */
final class StockObservationService
{
    private function key(int $provider, int $remote): string
    {
        return 'jianyun:stock:'.hash('sha256', config('app.key').'|'.config('database.connections.mysql.database').'|'.$provider.'|'.$remote);
    }

    public function remember(int $provider, int $remote, ?StockObservation $stock): array
    {
        $value = ['state' => $stock?->state ?? 'unknown', 'quantity' => $stock?->quantity, 'checked_at' => gmdate('Y-m-d H:i:s')];
        try {
            (new RedisStore())->command('SET', $this->key($provider, $remote), json_encode($value, JSON_THROW_ON_ERROR), 'EX', '300');
        } catch (\Throwable) { /* Display failure must not break a real preflight or committed operation. */
        }

        return $value;
    }

    public function read(int $provider, int $remote): array
    {
        return $this->readMany([0 => [$provider, $remote]])[0];
    }

    /** One cache round-trip for a directory; no remote fan-out. */
    public function readMany(array $bindings): array
    {
        if (! $bindings) {
            return [];
        }
        $unknown = ['state' => 'unknown', 'quantity' => null, 'checked_at' => null];
        $result = array_fill_keys(array_keys($bindings), $unknown);
        try {
            $keys = array_map(fn (array $binding): string => $this->key($binding[0], $binding[1]), array_values($bindings));
            $values = (new RedisStore())->command('MGET', ...$keys);
            if (! is_array($values) || count($values) !== count($keys)) {
                return $result;
            }
            foreach (array_keys($bindings) as $i => $id) {
                $result[$id] = $this->decode($values[$i]);
            }
        } catch (\Throwable) { /* Unknown stock does not break local forms or directory. */
        }

        return $result;
    }

    private function decode(mixed $raw): array
    {
        $unknown = ['state' => 'unknown', 'quantity' => null, 'checked_at' => null];
        try {
            if (! is_string($raw)) {
                return $unknown;
            }
            $v = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($v) || ! is_string($v['checked_at'] ?? null)) {
                return $unknown;
            }
            $stamp = strtotime($v['checked_at'].' UTC');
            if ($stamp === false || $stamp < time() - 300 || $stamp > time() + 5) {
                return $unknown;
            }
            $stock = new StockObservation($v['state'] ?? '', $v['quantity'] ?? null);

            return ['state' => $stock->state, 'quantity' => $stock->quantity, 'checked_at' => $v['checked_at']];
        } catch (\Throwable) {
            return $unknown;
        }
    }
}
