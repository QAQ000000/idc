<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\{ApiException, Database, Money, Request, Security};
use PDO;

final class BillingService
{
    public function listing(int $user,array $query):array
    {
        $page=Request::id($query['page']??1);$size=Request::id($query['per_page']??20);$status=$query['status']??'all';
        if ($page>100000 || $size>100 || !is_string($status) || !in_array($status,['all','unpaid','paid','cancelled','refund_pending','refunded'],true)) {
            throw new ApiException('Invalid invoice filter or pagination');
        }
        $params=[$user];$where='v.user_id=?';
        if ($status!=='all') { $where.=' AND v.status=?';$params[]=$status; }
        $db=Database::getInstance();$s=$db->prepare('SELECT COUNT(*) FROM invoices v WHERE '.$where);$s->execute($params);$total=(int)$s->fetchColumn();
        $offset=($page-1)*$size;
        $s=$db->prepare("SELECT v.id AS invoice_id,v.instance_id,v.type,v.total,v.status,v.payment_method,v.created_at,v.paid_at,v.quote_expired_at,i.name AS instance_name,
            (v.type='new' AND v.quote_expired_at<=UTC_TIMESTAMP()) AS quote_expired FROM invoices v LEFT JOIN instances i ON i.id=v.instance_id AND i.user_id=v.user_id
            WHERE {$where} ORDER BY v.id DESC LIMIT {$size} OFFSET {$offset}");$s->execute($params);
        return ['items'=>$s->fetchAll(),'total'=>$total,'page'=>$page,'per_page'=>$size];
    }
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
        $options = \App\Plugins\Providers\ConfigOptions::canonical($options);
        if (!empty($input['coupon_code'])) {
            throw new ApiException('Coupons are not enabled in this milestone');
        }
        $checkoutKey = isset($input['checkout_key']) ? Request::text($input, 'checkout_key', 16, 64) : null;
        if ($checkoutKey !== null && !preg_match('/^[a-zA-Z0-9_-]{16,64}$/D', $checkoutKey)) {
            throw new ApiException('Invalid checkout_key');
        }
        // HMAC 避免请求摘要泄漏可离线枚举的主机密码；不记录原始载荷。
        $requestHash = $checkoutKey === null ? null : Security::requestFingerprint(json_encode([
            $productId, $cycle, $hostname, $password, $options, $input['use_balance'] ?? false,
        ], JSON_THROW_ON_ERROR));
        // A keyed replay must not depend on today's upstream availability or price.
        if ($checkoutKey !== null) {
            $s = Database::getInstance()->prepare('SELECT id, checkout_hash FROM invoices WHERE user_id = ? AND checkout_key = ?');
            $s->execute([$userId, $checkoutKey]);
            if ($existing = $s->fetch()) {
                if (!hash_equals($existing['checkout_hash'], $requestHash)) { throw new ApiException('Checkout key already used with different parameters', 409, 409); }
                return $this->invoice($userId, (int)$existing['id']);
            }
        }
        $preflight = (new CatalogService())->preflight($productId, $cycle, $options);
        return Database::transaction(function (PDO $db) use ($userId, $productId, $cycle, $hostname, $password, $options, $input, $checkoutKey, $requestHash, $preflight): array {
            // 串行化同用户的新购。此处只锁用户及本次新行，不锁已有实例/账单。
            // 已有付款仍遵循实例→账单→钱包；重放只读已有订单，避免反向持锁。
            $stmt = $db->prepare('SELECT status FROM users WHERE id = ? FOR UPDATE');
            $stmt->execute([$userId]);
            if ($stmt->fetchColumn() !== 'active') { throw new ApiException('Account unavailable', 403, 403); }
            if ($checkoutKey !== null) {
                $stmt = $db->prepare('SELECT id, checkout_hash FROM invoices WHERE user_id = ? AND checkout_key = ?');
                $stmt->execute([$userId, $checkoutKey]);
                $existing = $stmt->fetch();
                if ($existing) {
                    if (!hash_equals($existing['checkout_hash'], $requestHash)) {
                        throw new ApiException('Checkout key already used with different parameters', 409, 409);
                    }
                    return $this->invoice($userId, (int)$existing['id']);
                }
            }
            $quote = (new CatalogService())->quote($db, $productId, $cycle, $options, true, $preflight);
            (new CatalogService())->assertConfirmed($quote, $preflight, $input['quote_token'] ?? null);
            \App\Plugins\Providers\PasswordPolicy::validate($password, $preflight['context']??[]);
            $product = $quote['product'];
            $snapshot = json_encode([
                'product_id'=>$productId, 'remote_pid'=>(int)$product['remote_pid'],
                'provider_driver'=>$product['driver'], 'is_auto_setup'=>(bool)$product['is_auto_setup'],
                'order_namespace'=>json_decode($product['config_cache'],true)['order_namespace']??'',
                'upstream_total'=>$preflight['amount']??null, 'upstream_recurring'=>$preflight['recurring']??null,
                'recurring_price'=>$quote['recurring_price'], 'total'=>$quote['total'],
                'markup_type'=>$product['markup_type'], 'markup_value'=>$product['markup_value'],
                'configuration'=>$quote['configuration'], 'config_options'=>$quote['config_options'],
                'driver_context'=>$preflight['context']??[], 'billing_cycle'=>$cycle,
                'stock_reserved'=>(int)$product['stock']>0,
            ],JSON_THROW_ON_ERROR);
            if ((int)$product['stock'] > 0 && !($input['use_balance'] ?? false)) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM invoices WHERE user_id = ? AND type = 'new' AND status = 'unpaid' AND JSON_EXTRACT(price_snapshot, '$.stock_reserved') = true");
                $stmt->execute([$userId]);
                if ((int)$stmt->fetchColumn() >= config('jianyun.max_unpaid_stock_holds')) {
                    throw new ApiException('Cancel or pay an existing stock reservation first', 409, 409);
                }
            }
            // 新购租期由交付成功后的原子激活起算，未付与等待交付均不消耗租期。
            $db->prepare('INSERT INTO instances (user_id, product_id, provider_id, name, encrypted_password, config_snapshot, billing_cycle, recurring_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$userId, $productId, $product['provider_id'], $hostname, Security::encrypt($password), $snapshot, $cycle, $quote['recurring_price']]);
            $instanceId = (int)$db->lastInsertId();
            $db->prepare("INSERT INTO invoices (user_id, instance_id, type, total, price_snapshot, quote_expired_at, checkout_key, checkout_hash) VALUES (?, ?, 'new', ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 MINUTE), ?, ?)")
                ->execute([$userId, $instanceId, $quote['total'], $snapshot, $checkoutKey, $requestHash]);
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
        $stmt = Database::getInstance()->prepare("SELECT id AS invoice_id, user_id, instance_id, type, total, status, payment_method, created_at,paid_at, quote_expired_at, price_snapshot,
            (type='new' AND quote_expired_at<=UTC_TIMESTAMP()) AS quote_expired FROM invoices WHERE id = ?");
        $stmt->execute([$id]);
        $invoice = $stmt->fetch();
        if (!$invoice) {
            throw new ApiException('Invoice not found', 404, 404);
        }
        if ((int)$invoice['user_id'] !== $userId) {
            throw new ApiException('Forbidden', 403, 403);
        }
        $snapshot = json_decode($invoice['price_snapshot'] ?? '{}', true, 64, JSON_THROW_ON_ERROR);
        $invoice['recurring_price'] = $snapshot['recurring_price'] ?? null;
        $invoice['config_options'] = $snapshot['config_options'] ?? [];
        $invoice['configuration'] = $snapshot['configuration'] ?? [];
        unset($invoice['user_id'], $invoice['price_snapshot']);
        return $invoice;
    }

    public static function assertPayable(PDO $db, array $instance, array $invoice): void
    {
        if ($invoice['status'] !== 'unpaid') { throw new ApiException('Invoice cannot be paid',409,409); }
        TaskGate::assertClear($db,(int)$instance['id']);
        if ($invoice['type'] === 'renew') { RenewalService::assertPayable($db,$instance,$invoice); return; }
        if ($invoice['type'] !== 'new' || $instance['status'] !== 'pending' || !$invoice['quote_expired_at']
            || strtotime($invoice['quote_expired_at'].' UTC') <= time()) { throw new ApiException('Quote expired or instance unavailable',409,409); }
    }

    public static function enqueuePaid(PDO $db, array $instance, array $invoice): void
    {
        if ($invoice['type'] === 'renew') { RenewalService::applyPayment($db,$instance,$invoice); return; }
        $db->prepare("INSERT INTO tasks (instance_id, invoice_id, idempotency_key, action, payload) VALUES (?, ?, ?, 'create', ?)")
            ->execute([$instance['id'],$invoice['id'],'instance_create:inv_'.$invoice['id'],$invoice['price_snapshot']]);
    }

    public function pay(int $userId, int $id, bool $automatic = false): array
    {
        return Database::transaction(function (PDO $db) use ($userId, $id, $automatic): array {
            // 先读取关联，再按统一顺序锁实例、账单，保留后续生命周期互斥边界。
            $invoice = $this->invoice($userId, $id);
            if (!in_array($invoice['type'], ['new','renew'],true) || !$invoice['instance_id']) {
                throw new ApiException('Only new purchase and renewal invoices support balance payment');
            }
            $stmt = $db->prepare('SELECT * FROM instances WHERE id = ? FOR UPDATE');
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
            if (!$instance || (int)$instance['user_id'] !== $userId) {
                throw new ApiException('Invoice cannot be paid', 409, 409);
            }
            if ($automatic && ($locked['type']!=='renew' || !(bool)$instance['auto_renew'] || strtotime($instance['due_date'].' UTC')>time()+7*86400)) {
                throw new ApiException('Automatic renewal is disabled or outside billing window',409,409);
            }
            self::assertPayable($db,$instance,$locked);
            $amount = Money::positive($locked['total']);
            $stmt = $db->prepare("UPDATE invoices SET status = 'paid', payment_method = 'balance', paid_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'unpaid'");
            $stmt->execute([$id]);
            if ($stmt->rowCount() !== 1) {
                throw new ApiException('Payment state conflict', 409, 409);
            }
            (new LedgerService())->wallet($db, $userId, $id, bcsub('0', $amount, 2), 'pay_invoice', "pay_invoice:inv_{$id}");
            $db->prepare('UPDATE users SET total_spent = total_spent + ? WHERE id = ?')->execute([$amount, $userId]);
            $db->prepare("INSERT INTO payment_records (invoice_id, user_id, channel, trade_no, amount, status, paid_at, payload) VALUES (?, ?, 'balance', ?, ?, 'success', UTC_TIMESTAMP(), '{\"settlement_purpose\":\"cloud_consumption\"}')")
                ->execute([$id, $userId, "balance:inv_{$id}", $amount]);
            self::enqueuePaid($db,$instance,$locked);
            return $this->invoice($userId, $id);
        });
    }

    public function cancel(int $userId, int $id, bool $onlyExpired = false): array
    {
        return Database::transaction(function (PDO $db) use ($userId, $id, $onlyExpired): array {
            $invoice = $this->invoice($userId, $id);
            if (!in_array($invoice['type'],['new','renew'],true) || !$invoice['instance_id']) {
                throw new ApiException('Only purchase or renewal invoices can be cancelled');
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
            if ($locked['status'] !== 'unpaid' || !$instance || ($locked['type']==='new' && $instance['status'] !== 'pending')) {
                throw new ApiException('Invoice cannot be cancelled', 409, 409);
            }
            $db->prepare("UPDATE invoices SET status = 'cancelled',cycle_marker=NULL WHERE id = ? AND status = 'unpaid'")->execute([$id]);
            if ($locked['type']==='renew') { return $this->invoice($userId,$id); }
            $db->prepare("UPDATE instances SET status = 'terminated' WHERE id = ?")->execute([$instance['id']]);
            $snapshot = json_decode($locked['price_snapshot'], true, 32, JSON_THROW_ON_ERROR);
            if ($snapshot['stock_reserved'] ?? false) {
                $db->prepare('UPDATE products SET stock = stock + 1 WHERE id = ? AND stock >= 0')->execute([$instance['product_id']]);
            }
            return $this->invoice($userId, $id);
        });
    }
}
