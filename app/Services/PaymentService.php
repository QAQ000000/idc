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
            $recharge = $invoice['type'] === 'recharge' && $invoice['instance_id'] === null;
            $instance = null;
            if (!$recharge) {
                $s = $db->prepare('SELECT * FROM instances WHERE id = ? AND user_id = ? FOR UPDATE'); $s->execute([$invoice['instance_id'], $userId]); $instance=$s->fetch();
                if (!$instance) { throw new ApiException('Invoice is not payable', 409, 409); }
            }
            $s = $db->prepare('SELECT *, quote_expired_at > UTC_TIMESTAMP() AS valid_quote FROM invoices WHERE id = ? FOR UPDATE'); $s->execute([$invoiceId]); $inv = $s->fetch();
            if ($recharge) {
                if ($inv['type'] !== 'recharge' || $inv['instance_id'] !== null || $inv['status'] !== 'unpaid') { throw new ApiException('Recharge invoice cannot be paid',409,409); }
            } else { BillingService::assertPayable($db,$instance,$inv); }
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
            $s = $db->prepare('SELECT *, quote_expired_at > UTC_TIMESTAMP() AS valid_quote FROM invoices WHERE id = ? FOR UPDATE'); $s->execute([$attempt['invoice_id']]); $inv = $s->fetch();
            if (!$inv || (int)$inv['user_id'] !== (int)$attempt['user_id']) { throw new ApiException('Payment ownership mismatch'); }
            $recharge = $inv['type'] === 'recharge' && $inv['instance_id'] === null;
            if (!$recharge && (!$instance || (int)$instance['user_id'] !== (int)$attempt['user_id'])) { throw new ApiException('Payment ownership mismatch'); }
            $s = $db->prepare('SELECT * FROM payment_records WHERE channel = ? AND gateway_trade_no = ? FOR UPDATE'); $s->execute([$channel, $n->gatewayTrade]);
            if ($existing = $s->fetch()) {
                $context = json_decode($existing['payload'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
                if (($context['merchant_order'] ?? '') !== $n->merchantOrder || (int)$existing['user_id'] !== (int)$attempt['user_id']
                    || bccomp($existing['amount'], $n->amount, 2) !== 0 || $existing['currency'] !== $n->currency) { throw new ApiException('Payment identity conflict'); }
                return; // Immutable settled receipt; no callback can overwrite its purpose/refund context.
            }
            $s = $db->prepare('SELECT * FROM payment_records WHERE id = ? FOR UPDATE'); $s->execute([$attempt['id']]); $attempt = $s->fetch();
            $normal = bccomp($inv['total'], $n->amount, 2) === 0;
            if ($normal && $recharge) { $normal = $inv['status'] === 'unpaid'; }
            elseif ($normal) {
                try { BillingService::assertPayable($db,$instance,$inv); }
                catch (ApiException $e) { if ($e->httpStatus !== 409) { throw $e; } $normal = false; }
            }
            $terminationBlocked = !$normal && $inv['type']==='renew' && $inv['status']==='unpaid' && RenewalService::terminationBlocks($db,$instance);
            $purpose = $normal ? ($recharge ? 'wallet_recharge' : 'cloud_consumption') : 'late_wallet_credit';
            $context=['merchant_order' => $n->merchantOrder, 'settlement_purpose' => $purpose];
            if($terminationBlocked){$context['credit_reason']='termination_blocked_renewal';}
            $payload = json_encode($context, JSON_THROW_ON_ERROR);
            $status = $normal ? 'success' : 'late_credited_to_balance';
            if ($attempt['status'] === 'pending' && $attempt['gateway_trade_no'] === null) {
                $db->prepare('UPDATE payment_records SET gateway_trade_no = ?, amount = ?, status = ?, paid_at = UTC_TIMESTAMP(), payload = ? WHERE id = ?')
                    ->execute([$n->gatewayTrade, $n->amount, $status, $payload, $attempt['id']]);
                $receiptId = (int)$attempt['id'];
                $receiptTrade = $attempt['trade_no'];
            } else {
                // A gateway can report a distinct real payment against the same merchant order.
                $receiptTrade = 'receipt_' . bin2hex(random_bytes(20));
                $db->prepare('INSERT INTO payment_records (invoice_id,user_id,channel,trade_no,gateway_trade_no,amount,currency,status,paid_at,payload) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),?)')
                    ->execute([$inv['id'], $attempt['user_id'], $channel, $receiptTrade, $n->gatewayTrade, $n->amount, $n->currency, $status, $payload]);
                $receiptId = (int)$db->lastInsertId();
            }
            if ($normal) {
                $s = $db->prepare("UPDATE invoices SET status = 'paid', payment_method = ?, paid_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'unpaid'"); $s->execute([$channel, $inv['id']]);
                if ($s->rowCount() !== 1) { throw new ApiException('Invoice settlement conflict',409,409); }
                if ($recharge) {
                    (new LedgerService())->wallet($db,(int)$attempt['user_id'],(int)$inv['id'],$n->amount,'recharge','recharge:pay_'.$receiptId,$receiptTrade);
                } else {
                    $db->prepare('UPDATE users SET total_spent = total_spent + ? WHERE id = ?')->execute([$n->amount, $attempt['user_id']]);
                    BillingService::enqueuePaid($db,$instance,$inv);
                }
            } else {
                if($terminationBlocked){$db->prepare("UPDATE invoices SET status='refunded',cycle_marker=NULL WHERE id=? AND status='unpaid'")->execute([$inv['id']]);}
                (new LedgerService())->wallet($db, (int)$attempt['user_id'], (int)$inv['id'], $n->amount, $terminationBlocked?'refund_to_balance':'late_payment_credit', 'late_credit:pay_' . $receiptId, $receiptTrade);
            }
        });
    }
}
