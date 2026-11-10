<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\{ApiException,Database,Request};
use PDO;

/** Read-only projections of original financial facts; never expose arbitrary JSON or credentials. */
final class FinanceQueryService
{
    private const INVOICE = 'v.id AS invoice_id,v.user_id,v.instance_id,v.type,v.total,v.status,v.payment_method,v.created_at,v.paid_at';

    private const RECEIPT = "r.id,r.invoice_id,r.user_id,r.channel,r.trade_no,r.gateway_trade_no,r.amount,r.currency,r.status,r.refund_status,r.refund_amount,r.created_at,r.paid_at,JSON_UNQUOTE(JSON_EXTRACT(r.payload,'$.settlement_purpose')) AS settlement_purpose";

    private const TRANSACTION = 't.id,t.user_id,t.invoice_id,t.type,t.amount,t.balance_before,t.balance_after,t.created_at';

    private const TYPES = ['recharge', 'pay_invoice', 'refund', 'refund_rollback', 'refund_to_balance', 'commission_settle', 'commission_clawback', 'affiliate_to_balance', 'withdrawal_freeze', 'withdrawal_payout', 'withdrawal_reject_rollback', 'late_payment_credit'];

    public function wallet(int $user, array $query): array
    {
        return $this->listing('transactions', $query, $user);
    }

    public function adminListing(int $admin, string $kind, array $query): array
    {
        $this->authorize($admin);

        return $this->listing($kind, $query);
    }

    public function detail(int $admin, int $id, array $query): array
    {
        $this->authorize($admin);

        return Database::transaction(function (PDO $db) use ($id, $query): array {
            $s = $db->prepare('SELECT '.self::INVOICE.' FROM invoices v WHERE v.id=?');
            $s->execute([$id]);
            $invoice = $s->fetch();
            if (! $invoice) {
                throw new ApiException('Invoice not found', 404, 404);
            }
            $invoice['fulfillment_status'] = (new BillingService())->invoice((int) $invoice['user_id'], $id)['fulfillment_status'];
            $s = $db->prepare('SELECT id,email,balance,status FROM users WHERE id=?');
            $s->execute([$invoice['user_id']]);

            return ['invoice' => $invoice, 'customer' => $s->fetch(),
                'receipts' => $this->listing('receipts', ['invoice_id' => $id, 'page' => $query['receipts_page'] ?? 1]),
                'transactions' => $this->listing('transactions', ['invoice_id' => $id, 'page' => $query['transactions_page'] ?? 1])];
        });
    }

    private function authorize(int $admin): void
    {
        $s = Database::getInstance()->prepare('SELECT role FROM admins WHERE id=? AND status=\'active\'');
        $s->execute([$admin]);
        if (! in_array($s->fetchColumn(), ['super_admin', 'finance'], true)) {
            throw new ApiException('Finance permission required', 403, 403);
        }
    }

    private function page(mixed $value, int $max = 100000): int
    {
        $n = Request::id($value);
        if ($n > $max) {
            throw new ApiException('Pagination limit exceeded');
        }

        return $n;
    }

    // Correlate new entries by the existing trade_no column; preserve old immutable ledger rows.
    private function receiptLink(): string
    {
        return "r.invoice_id=t.invoice_id AND r.user_id=t.user_id AND (r.trade_no=t.trade_no OR t.idempotency_key=CONCAT('late_credit:pay_',r.id) OR t.idempotency_key=CONCAT('recharge:pay_',r.id) OR (r.channel='balance' AND t.idempotency_key=CONCAT('pay_invoice:inv_',r.invoice_id)))";
    }

    private function listing(string $kind, array $query, ?int $owner = null): array
    {
        $page = $this->page($query['page'] ?? 1);
        $size = $this->page($query['per_page'] ?? 20, 100);
        [$alias,$table,$columns,$date] = match ($kind) {
            'invoices' => ['v', 'invoices', self::INVOICE, 'v.created_at'],
            'receipts' => ['r', 'payment_records', self::RECEIPT, 'COALESCE(r.paid_at,r.created_at)'],
            'transactions' => ['t', 'transactions', self::TRANSACTION, 't.created_at'],
            default => throw new ApiException('Invalid finance source'),
        };
        $where = [];
        $params = [];
        if ($kind === 'transactions') {
            $where[] = "t.account_type='wallet'";
            $columns .= ',(SELECT r.id FROM payment_records r WHERE '.$this->receiptLink().' ORDER BY r.id LIMIT 1) AS payment_record_id';
        }
        if ($owner !== null) {
            $where[] = "$alias.user_id=?";
            $params[] = $owner;
        } elseif (isset($query['user_id']) && $query['user_id'] !== '') {
            $where[] = "$alias.user_id=?";
            $params[] = Request::id($query['user_id']);
        }
        if ($owner === null && isset($query['email']) && $query['email'] !== '') {
            $where[] = 'u.email=?';
            $params[] = Request::text($query, 'email', 1, 100);
        }
        if (isset($query['invoice_id']) && $query['invoice_id'] !== '') {
            $where[] = ($kind === 'invoices' ? 'v.id' : "$alias.invoice_id").'=?';
            $params[] = Request::id($query['invoice_id']);
        }
        if ($owner === null && isset($query['instance_id']) && $query['instance_id'] !== '') {
            $where[] = $kind === 'invoices' ? "EXISTS(SELECT 1 FROM instances i WHERE i.id=v.instance_id AND i.user_id=v.user_id AND i.id=?)" : "EXISTS(SELECT 1 FROM invoices v JOIN instances i ON i.id=v.instance_id AND i.user_id=v.user_id WHERE v.id=$alias.invoice_id AND v.user_id=$alias.user_id AND i.id=?)";
            $params[] = Request::id($query['instance_id']);
        }
        if (isset($query['type']) && $query['type'] !== '') {
            $type = Request::text($query, 'type', 1, 50);
            if ($kind !== 'transactions' || ! in_array($type, self::TYPES, true)) {
                throw new ApiException('Invalid ledger type');
            }
            $where[] = 't.type=?';
            $params[] = $type;
        }
        if ($owner === null) {
            $receiptWhere = [];
            $receiptParams = [];
            foreach (['gateway_trade_no' => 100, 'channel' => 50] as $key => $max) {
                if (isset($query[$key]) && $query[$key] !== '') {
                    $receiptWhere[] = 'r.'.$key.'=?';
                    $receiptParams[] = Request::text($query, $key, 1, $max);
                }
            }
            if ($receiptWhere) {
                $condition = implode(' AND ', $receiptWhere);
                $where[] = match ($kind) {
                    'invoices' => "EXISTS(SELECT 1 FROM payment_records r WHERE r.invoice_id=v.id AND r.user_id=v.user_id AND $condition)",
                    'receipts' => $condition,
                    'transactions' => 'EXISTS(SELECT 1 FROM payment_records r WHERE '.$this->receiptLink().' AND '.$condition.')',
                };
                array_push($params, ...$receiptParams);
            }
            $dates = [];
            foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
                if (! isset($query[$key]) || $query[$key] === '')continue;
                $value = Request::text($query, $key, 19, 19);
                $time = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
                if (! $time || $time->format('Y-m-d H:i:s') !== $value) {
                    throw new ApiException('Invalid UTC time');
                }
                $dates[$key] = $value;
                $where[] = "$date $op ?";
                $params[] = $value;
            }
            if (isset($dates['from'],$dates['to']) && $dates['from'] > $dates['to']) {
                throw new ApiException('Invalid time range');
            }
            $columns .= ',u.email AS user_email';
        }
        $sql = " FROM $table $alias JOIN users u ON u.id=$alias.user_id".($where ? ' WHERE '.implode(' AND ', $where) : '');
        $db = Database::getInstance();
        $s = $db->prepare('SELECT COUNT(*)'.$sql);
        $s->execute($params);
        $total = (int) $s->fetchColumn();
        $offset = ($page - 1) * $size;
        $s = $db->prepare("SELECT $columns $sql ORDER BY $alias.id DESC LIMIT $size OFFSET $offset");
        $s->execute($params);

        return ['items' => $s->fetchAll(), 'total' => $total, 'page' => $page, 'per_page' => $size];
    }
}
