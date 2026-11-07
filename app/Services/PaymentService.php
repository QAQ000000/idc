<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\{ApiException, Database, Security, Money};
use App\Plugins\Payments\{PaymentRegistry, PaymentNotification};
use PDO;

final class PaymentService
{
    private function gateway(string $channel): array
    {
        $s = Database::getInstance()->prepare('SELECT value, encrypted_credentials FROM system_settings WHERE `key` = ?');
        $s->execute(['payment.' . $channel]); $row = $s->fetch();
        if (!$row || !($config = json_decode($row['value'] ?? '{}', true)) || !($config['enabled'] ?? false)) { throw new ApiException('Payment channel unavailable', 409, 409); }
        $secret = Security::decrypt($row['encrypted_credentials'] ?? '');
        if ($secret === null) { throw new \RuntimeException('Payment credentials unavailable'); }
        return [PaymentRegistry::installed()->get($channel), $config + json_decode($secret, true, 32, JSON_THROW_ON_ERROR)];
    }

    public function checkout(int $userId, int $invoiceId, string $channel): array
    {
        [$gateway, $config] = $this->gateway($channel);
        $p = Database::transaction(static function (PDO $db) use ($userId, $invoiceId, $channel): array {
            $invoice = (new BillingService())->invoice($userId, $invoiceId);
            $s = $db->prepare('SELECT status FROM instances WHERE id = ? AND user_id = ? FOR UPDATE'); $s->execute([$invoice['instance_id'], $userId]);
            if ($s->fetchColumn() !== 'pending') { throw new ApiException('Invoice is not payable', 409, 409); }
            TaskGate::assertClear($db, (int)$invoice['instance_id']);
            $s = $db->prepare('SELECT *, quote_expired_at > UTC_TIMESTAMP() AS valid_quote FROM invoices WHERE id = ? FOR UPDATE'); $s->execute([$invoiceId]); $inv = $s->fetch();
            if ($inv['status'] !== 'unpaid' || $inv['type'] !== 'new' || !$inv['valid_quote']) { throw new ApiException('Invoice is not payable', 409, 409); }
            // A new click is a new attempt. Older cashier attempts remain attributable if paid late;
            // never resubmit an existing attempt to gateways that reject duplicate merchant orders.
            $p = ['invoice_id' => $invoiceId, 'trade_no' => 'jy_' . bin2hex(random_bytes(20)), 'amount' => Money::positive($inv['total'])];
            $db->prepare("INSERT INTO payment_records (invoice_id, user_id, channel, trade_no, amount, payload) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$invoiceId, $userId, $channel, $p['trade_no'], $p['amount'], json_encode(['merchant_order' => $p['trade_no']], JSON_THROW_ON_ERROR)]);
            return $p;
        });
        return ['checkout' => $gateway->checkout($p, $config)];
    }

    public function notify(string $channel, array $input): void
    {
        [$gateway, $config] = $this->gateway($channel);
        $n = $gateway->verify($input, $config);
        $this->settle($channel, $n);
    }

    /** Call only with a verified gateway result. Receipt and all settlement effects share this transaction. */
    private function settle(string $channel, PaymentNotification $n): void
    {
        Database::transaction(static function (PDO $db) use ($channel, $n): void {
            $s = $db->prepare('SELECT * FROM payment_records WHERE trade_no = ? AND channel = ?'); $s->execute([$n->merchantOrder, $channel]); $attempt = $s->fetch();
            if (!$attempt || $attempt['currency'] !== $n->currency || $n->currency !== 'CNY') { throw new ApiException('Unattributable payment'); }
            $s = $db->prepare('SELECT * FROM instances WHERE id = (SELECT instance_id FROM invoices WHERE id = ?) FOR UPDATE'); $s->execute([$attempt['invoice_id']]); $instance = $s->fetch();
            if (!$instance || (int)$instance['user_id'] !== (int)$attempt['user_id']) { throw new ApiException('Payment ownership mismatch'); }
            $s = $db->prepare('SELECT *, quote_expired_at > UTC_TIMESTAMP() AS valid_quote FROM invoices WHERE id = ? FOR UPDATE'); $s->execute([$attempt['invoice_id']]); $inv = $s->fetch();
            if ((int)$inv['user_id'] !== (int)$attempt['user_id']) { throw new ApiException('Payment ownership mismatch'); }
            $s = $db->prepare('SELECT * FROM payment_records WHERE channel = ? AND gateway_trade_no = ? FOR UPDATE'); $s->execute([$channel, $n->gatewayTrade]);
            if ($existing = $s->fetch()) {
                $context = json_decode($existing['payload'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
                if (($context['merchant_order'] ?? '') !== $n->merchantOrder || (int)$existing['user_id'] !== (int)$attempt['user_id']
                    || bccomp($existing['amount'], $n->amount, 2) !== 0 || $existing['currency'] !== $n->currency) { throw new ApiException('Payment identity conflict'); }
                return; // Immutable settled receipt; no callback can overwrite its purpose/refund context.
            }
            $s = $db->prepare('SELECT * FROM payment_records WHERE id = ? FOR UPDATE'); $s->execute([$attempt['id']]); $attempt = $s->fetch();
            $normal = $inv['type'] === 'new' && $inv['status'] === 'unpaid' && $inv['valid_quote'] && $instance['status'] === 'pending'
                && bccomp($inv['total'], $n->amount, 2) === 0;
            if ($normal) {
                try { TaskGate::assertClear($db, (int)$instance['id']); }
                catch (ApiException $e) { if ($e->httpStatus !== 409) { throw $e; } $normal = false; }
            }
            $purpose = $normal ? 'cloud_consumption' : 'late_wallet_credit';
            $payload = json_encode(['merchant_order' => $n->merchantOrder, 'settlement_purpose' => $purpose], JSON_THROW_ON_ERROR);
            $status = $normal ? 'success' : 'late_credited_to_balance';
            if ($attempt['status'] === 'pending' && $attempt['gateway_trade_no'] === null) {
                $db->prepare('UPDATE payment_records SET gateway_trade_no = ?, amount = ?, status = ?, paid_at = UTC_TIMESTAMP(), payload = ? WHERE id = ?')
                    ->execute([$n->gatewayTrade, $n->amount, $status, $payload, $attempt['id']]);
                $receiptId = (int)$attempt['id'];
            } else {
                // A gateway can report a distinct real payment against the same merchant order.
                $db->prepare('INSERT INTO payment_records (invoice_id,user_id,channel,trade_no,gateway_trade_no,amount,currency,status,paid_at,payload) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),?)')
                    ->execute([$inv['id'], $attempt['user_id'], $channel, 'receipt_' . bin2hex(random_bytes(20)), $n->gatewayTrade, $n->amount, $n->currency, $status, $payload]);
                $receiptId = (int)$db->lastInsertId();
            }
            if ($normal) {
                $s = $db->prepare("UPDATE invoices SET status = 'paid', payment_method = ?, paid_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'unpaid'"); $s->execute([$channel, $inv['id']]);
                if ($s->rowCount() !== 1) { throw new ApiException('Invoice settlement conflict',409,409); }
                $db->prepare('UPDATE users SET total_spent = total_spent + ? WHERE id = ?')->execute([$n->amount, $attempt['user_id']]);
                $db->prepare("INSERT INTO tasks (instance_id,invoice_id,idempotency_key,action,payload) VALUES (?,?,?,'create',?)")
                    ->execute([$instance['id'], $inv['id'], 'instance_create:inv_' . $inv['id'], $inv['price_snapshot']]);
            } else {
                (new LedgerService())->wallet($db, (int)$attempt['user_id'], (int)$inv['id'], $n->amount, 'late_payment_credit', 'late_credit:pay_' . $receiptId);
            }
        });
    }
}
