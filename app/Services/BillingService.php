<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\{ApiException, Database, Money, Request, Security};
use PDO;

final class BillingService
{
    public function checkout(int $userId, array $input): array
    {
        $productId = Request::id($input['product_id'] ?? null);
        $cycle = Request::text($input, 'billing_cycle', 1, 20);
        $hostname = Request::text($input, 'hostname', 1, 63);
        if (!preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9-]*[a-zA-Z0-9])?$/D', $hostname)) {
            throw new ApiException('Invalid hostname');
        }
        $password = Request::text($input, 'password', 12, 72);
        $options = $input['config_options'] ?? [];
        if (!is_array($options) || (isset($input['use_balance']) && !is_bool($input['use_balance']))) {
            throw new ApiException('Invalid order options');
        }
        if (!empty($input['coupon_code'])) {
            throw new ApiException('Coupons are not enabled in this milestone');
        }
        return Database::transaction(function (PDO $db) use ($userId, $productId, $cycle, $hostname, $password, $options, $input): array {
            $quote = (new CatalogService())->quote($db, $productId, $cycle, $options, true);
            $product = $quote['product'];
            $snapshot = json_encode(['product_id' => $productId, 'billing_cycle' => $cycle, 'config_options' => $quote['config_options'], 'total' => $quote['total'], 'stock_reserved' => (int)$product['stock'] > 0], JSON_THROW_ON_ERROR);
            $due = (new \DateTimeImmutable())->modify($cycle === 'monthly' ? '+1 month' : '+1 year')->format('Y-m-d H:i:s');
            $db->prepare('INSERT INTO instances (user_id, product_id, provider_id, name, encrypted_password, config_snapshot, billing_cycle, recurring_price, due_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$userId, $productId, $product['provider_id'], $hostname, Security::encrypt($password), $snapshot, $cycle, $quote['total'], $due]);
            $instanceId = (int)$db->lastInsertId();
            $db->prepare("INSERT INTO invoices (user_id, instance_id, type, total, price_snapshot, quote_expired_at) VALUES (?, ?, 'new', ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 MINUTE))")
                ->execute([$userId, $instanceId, $quote['total'], $snapshot]);
            $invoiceId = (int)$db->lastInsertId();
            // 本地库存作为预留：未付款也占用一个名额；取消时归还。
            if ((int)$product['stock'] > 0) {
                $db->prepare('UPDATE products SET stock = stock - 1 WHERE id = ?')->execute([$productId]);
            }
            if ($input['use_balance'] ?? false) {
                return $this->pay($userId, $invoiceId);
            }
            return $this->invoice($userId, $invoiceId);
        });
    }

    public function invoice(int $userId, int $id): array
    {
        $stmt = Database::getInstance()->prepare('SELECT id AS invoice_id, user_id, instance_id, type, total, status, payment_method, paid_at, quote_expired_at FROM invoices WHERE id = ?');
        $stmt->execute([$id]);
        $invoice = $stmt->fetch();
        if (!$invoice) {
            throw new ApiException('Invoice not found', 404, 404);
        }
        if ((int)$invoice['user_id'] !== $userId) {
            throw new ApiException('Forbidden', 403, 403);
        }
        unset($invoice['user_id']);
        return $invoice;
    }

    public function pay(int $userId, int $id): array
    {
        return Database::transaction(function (PDO $db) use ($userId, $id): array {
            // 先读取关联，再按统一顺序锁实例、账单，保留后续生命周期互斥边界。
            $invoice = $this->invoice($userId, $id);
            if ($invoice['type'] !== 'new' || !$invoice['instance_id']) {
                throw new ApiException('Only new purchase invoices support balance payment in this milestone');
            }
            $stmt = $db->prepare('SELECT id, status, user_id FROM instances WHERE id = ? FOR UPDATE');
            $stmt->execute([$invoice['instance_id']]);
            $instance = $stmt->fetch();
            $stmt = $db->prepare('SELECT *, quote_expired_at <= UTC_TIMESTAMP() AS expired FROM invoices WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $locked = $stmt->fetch();
            if ($locked['status'] === 'paid') {
                // REPEATABLE READ 下首次归属查询可能保留旧快照，响应使用锁定读的最新状态。
                $invoice['status'] = $locked['status'];
                $invoice['payment_method'] = $locked['payment_method'];
                $invoice['paid_at'] = $locked['paid_at'];
                return $invoice;
            }
            if (!$instance || (int)$instance['user_id'] !== $userId || $instance['status'] !== 'pending' || $locked['status'] !== 'unpaid') {
                throw new ApiException('Invoice cannot be paid', 409, 409);
            }
            if (!$locked['quote_expired_at'] || $locked['expired']) {
                throw new ApiException('Quote expired; cancel and place a new order', 409, 409);
            }
            $amount = Money::positive($locked['total']);
            $stmt = $db->prepare("UPDATE invoices SET status = 'paid', payment_method = 'balance', paid_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'unpaid'");
            $stmt->execute([$id]);
            if ($stmt->rowCount() !== 1) {
                throw new ApiException('Payment state conflict', 409, 409);
            }
            (new LedgerService())->wallet($db, $userId, $id, bcsub('0', $amount, 2), 'pay_invoice', "pay_invoice:inv_{$id}");
            $db->prepare('UPDATE users SET total_spent = total_spent + ? WHERE id = ?')->execute([$amount, $userId]);
            $db->prepare("INSERT INTO payment_records (invoice_id, user_id, channel, trade_no, amount, status, paid_at) VALUES (?, ?, 'balance', ?, ?, 'success', UTC_TIMESTAMP())")
                ->execute([$id, $userId, "balance:inv_{$id}", $amount]);
            $db->prepare("INSERT INTO tasks (instance_id, invoice_id, idempotency_key, action, payload) VALUES (?, ?, ?, 'create', ?)")
                ->execute([$instance['id'], $id, "instance_create:inv_{$id}", $locked['price_snapshot']]);
            return $this->invoice($userId, $id);
        });
    }

    public function cancel(int $userId, int $id, bool $onlyExpired = false): array
    {
        return Database::transaction(function (PDO $db) use ($userId, $id, $onlyExpired): array {
            $invoice = $this->invoice($userId, $id);
            if ($invoice['type'] !== 'new' || !$invoice['instance_id']) {
                throw new ApiException('Only new purchase invoices can be cancelled');
            }
            $stmt = $db->prepare('SELECT id, product_id, status FROM instances WHERE id = ? FOR UPDATE');
            $stmt->execute([$invoice['instance_id']]);
            $instance = $stmt->fetch();
            $stmt = $db->prepare('SELECT *, quote_expired_at <= UTC_TIMESTAMP() AS expired FROM invoices WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $locked = $stmt->fetch();
            if ($locked['status'] === 'cancelled' || ($onlyExpired && (!$locked['expired'] || $locked['status'] !== 'unpaid'))) {
                $invoice['status'] = $locked['status'];
                $invoice['payment_method'] = $locked['payment_method'];
                $invoice['paid_at'] = $locked['paid_at'];
                return $invoice;
            }
            if ($locked['status'] !== 'unpaid' || !$instance || $instance['status'] !== 'pending') {
                throw new ApiException('Invoice cannot be cancelled', 409, 409);
            }
            $db->prepare("UPDATE invoices SET status = 'cancelled' WHERE id = ? AND status = 'unpaid'")->execute([$id]);
            $db->prepare("UPDATE instances SET status = 'terminated' WHERE id = ?")->execute([$instance['id']]);
            $snapshot = json_decode($locked['price_snapshot'], true, 32, JSON_THROW_ON_ERROR);
            if ($snapshot['stock_reserved'] ?? false) {
                $db->prepare('UPDATE products SET stock = stock + 1 WHERE id = ? AND stock >= 0')->execute([$instance['product_id']]);
            }
            return $this->invoice($userId, $id);
        });
    }
}
