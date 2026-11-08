<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\{ApiException, Money};
use PDO;

final class LedgerService
{
    /** 必须由调用方置于支付/充值事务中，余额更新与流水始终一起提交。 */
    public function wallet(PDO $db, int $userId, int $invoiceId, string $amount, string $type, string $key, ?string $tradeNo = null): void
    {
        if (!$db->inTransaction() || !in_array($type, ['pay_invoice', 'recharge', 'late_payment_credit', 'refund_to_balance'], true)) {
            throw new \LogicException('Ledger requires a transaction and a supported operation');
        }
        $stmt = $db->prepare('SELECT balance, status FROM users WHERE id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user || ($user['status'] !== 'active' && !in_array($type, ['recharge', 'late_payment_credit', 'refund_to_balance'], true))) {
            throw new ApiException('Account unavailable', 403, 403);
        }
        $after = Money::add($user['balance'], $amount);
        if (bccomp($after, '0', 2) < 0) {
            throw new ApiException('Insufficient balance', 409, 1001);
        }
        $db->prepare('UPDATE users SET balance = ? WHERE id = ?')->execute([$after, $userId]);
        $db->prepare('INSERT INTO transactions (user_id, invoice_id, account_type, type, amount, balance_before, balance_after, idempotency_key, remark, trade_no) VALUES (?, ?, \'wallet\', ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$userId, $invoiceId, $type, $amount, $user['balance'], $after, $key, $type, $tradeNo]);
    }
}
