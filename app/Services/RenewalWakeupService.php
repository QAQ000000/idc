<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\RedisStore;
use Illuminate\Support\Facades\{DB, Redis};

/** Loss-tolerant customer hints only. MySQL invoices, not Redis, authorize every debit. */
final class RenewalWakeupService
{
    private static int $redisRetryAfter = 0;

    private static function key(): string
    {
        // Separate environments even when they share a Redis database; never publish credentials.
        return 'jianyun:renewals:wakeup:'.hash('sha256', config('app.key').'|'.config('database.connections.mysql.database'));
    }

    /** Register only after a new normal recharge ledger entry, within its owning transaction. */
    public function afterRecharge(int $userId): void
    {
        if ($userId < 1 || DB::connection()->transactionLevel() < 1) {
            throw new \LogicException('Recharge wake-up requires a customer and an active transaction');
        }
        DB::connection()->afterCommit(static function () use ($userId): void {
            // Redis or logging failure must never turn an already committed receipt into payment failure.
            try {
                if (time() < self::$redisRetryAfter) { return; }
                if ((new RedisStore())->command('SADD', self::key(), (string)$userId) === null) {
                    throw new \RuntimeException('Wake-up hint unavailable');
                }
            } catch (\Throwable) { self::redisUnavailable(); }
        });
    }

    /** Called outside transactions by the existing financial Worker, never by a payment callback. */
    public function runOnce(): bool
    {
        if (DB::connection()->transactionLevel() !== 0) {
            throw new \LogicException('Renewal wake-up must run after recharge commit');
        }
        if (time() < self::$redisRetryAfter) { return false; }
        try { $user = (new RedisStore())->command('SPOP', self::key()); }
        catch (\Throwable) { self::redisUnavailable(); return false; }
        if ($user === null) { return false; }
        if (!is_string($user) || !ctype_digit($user) || !filter_var($user, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]])) {
            return true; // Discard malformed infrastructure hints; they cannot authorize a payment.
        }
        (new RenewalService())->debitDue((int)$user);
        return true;
    }

    private static function redisUnavailable(): void
    {
        self::$redisRetryAfter = time() + 60;
        try { Redis::purge('default'); } catch (\Throwable) { /* Reconnect on the next eligible attempt. */ }
        try { logger()->warning('Renewal wake-up unavailable; scheduled MySQL debit remains active'); }
        catch (\Throwable) { /* Observability cannot undo a committed recharge. */ }
    }
}
