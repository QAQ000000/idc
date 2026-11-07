<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\{ApiException, Database, Money};
use PDO;

/** 演示资金绝不提供 HTTP 接口，生产环境也不能从 CLI 注入。 */
final class DemoService
{
    private function guard(): void
    {
        if (PHP_SAPI !== 'cli' || !in_array(config('app.env'), ['local', 'test'], true)) {
            throw new \RuntimeException('Demo commands require CLI and APP_ENV=local or test');
        }
    }

    public function seed(): int
    {
        $this->guard();
        return Database::transaction(static function (PDO $db): int {
            $db->prepare("INSERT INTO providers (name, driver) VALUES ('本地演示人工交付', 'manual')")->execute();
            $providerId = (int)$db->lastInsertId();
            $cache = json_encode(['prices' => ['monthly' => '10.00', 'annually' => '100.00'], 'fixed_config' => ['cpu' => 1, 'memory_mb' => 1024]], JSON_THROW_ON_ERROR);
            $db->prepare("INSERT INTO products (provider_id, name, markup_type, config_cache, is_auto_setup) VALUES (?, '演示云主机（不实际开通）', 'none', ?, 0)")->execute([$providerId, $cache]);
            return (int)$db->lastInsertId();
        });
    }

    public function recharge(int $userId, string $amount, string $reference): int
    {
        $this->guard();
        $amount = Money::positive($amount);
        if (!preg_match('/^[a-zA-Z0-9_-]{1,60}$/D', $reference)) {
            throw new ApiException('Invalid recharge reference');
        }
        return Database::transaction(static function (PDO $db) use ($userId, $amount, $reference): int {
            $stmt = $db->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
            $stmt->execute([$userId]);
            if (!$stmt->fetchColumn()) {
                throw new ApiException('User not found', 404, 404);
            }
            $key = 'demo_recharge:' . $reference;
            $stmt = $db->prepare('SELECT invoice_id, user_id, amount FROM transactions WHERE idempotency_key = ?');
            $stmt->execute([$key]);
            $existing = $stmt->fetch();
            if ($existing) {
                if ((int)$existing['user_id'] !== $userId || bccomp($existing['amount'], $amount, 2) !== 0) {
                    throw new ApiException('Recharge reference already used with different parameters', 409, 409);
                }
                return (int)$existing['invoice_id'];
            }
            $db->prepare("INSERT INTO invoices (user_id, type, total, status, payment_method, paid_at) VALUES (?, 'recharge', ?, 'paid', 'demo', UTC_TIMESTAMP())")->execute([$userId, $amount]);
            $id = (int)$db->lastInsertId();
            (new LedgerService())->wallet($db, $userId, $id, $amount, 'recharge', $key);
            $db->prepare("INSERT INTO payment_records (invoice_id, user_id, channel, trade_no, amount, status, paid_at) VALUES (?, ?, 'demo', ?, ?, 'success', UTC_TIMESTAMP())")->execute([$id, $userId, $key, $amount]);
            return $id;
        });
    }
}
