<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\{ApiException, Database, Request, Money};
use PDO;

final class TaskResolutionService
{
    /** Dedicated audited CLI/HTTP path. Unknown results require evidence, never a generic retry button. */
    public function resolve(int $adminId, int $taskId, array $input): void
    {
        $action = Request::text($input, 'action', 1, 30);
        $evidence = trim(Request::text($input, 'evidence', 10, 1000));
        if (strlen($evidence) < 10) { throw new ApiException('Evidence must contain at least 10 bytes'); }
        $token = Request::id($input['expected_execution_token'] ?? null);
        if (!in_array($action, ['retry', 'fulfill', 'cancel_compensate', 'investigate'], true)) { throw new ApiException('Unsupported resolution'); }
        if (in_array($action, ['retry','cancel_compensate'], true)
            && ($input['confirmed_no_execution_or_pending_request'] ?? false) !== true) {
            throw new ApiException('Confirm no asset, debit or delayed execution before resolving');
        }
        Database::transaction(static function (PDO $db) use ($adminId, $taskId, $input, $action, $evidence, $token): void {
            $s = $db->prepare('SELECT instance_id FROM tasks WHERE id = ?'); $s->execute([$taskId]); $iid = $s->fetchColumn();
            if (!$iid) { throw new ApiException('Task not found', 404, 404); }
            $s = $db->prepare('SELECT * FROM instances WHERE id = ? FOR UPDATE'); $s->execute([$iid]); $instance = $s->fetch();
            TaskGate::assertClear($db, (int)$iid, $taskId);
            $s = $db->prepare('SELECT * FROM tasks WHERE id = ? FOR UPDATE'); $s->execute([$taskId]); $t = $s->fetch();
            if (!$t || !$instance || (int)$t['execution_token'] !== $token) {
                throw new ApiException('Task execution token is no longer valid', 409, 409);
            }
            $s = $db->prepare("SELECT * FROM invoices WHERE id = ? AND instance_id = ? AND user_id = ? AND type = ? FOR UPDATE");
            $s->execute([$t['invoice_id'], $iid, $instance['user_id'], $t['action']==='renew'?'renew':'new']); $inv = $s->fetch();
            if (!$inv || $inv['status'] !== 'paid') { throw new ApiException('Resolution requires a paid purchase', 409, 409); }
            $s = $db->prepare("SELECT id FROM admins WHERE id = ? AND role = 'super_admin' AND status = 'active' FOR UPDATE"); $s->execute([$adminId]);
            if (!$s->fetch()) { throw new ApiException('Active super_admin required', 403, 403); }
            if (!in_array($action, self::candidateActions($t, $instance, $inv, 'super_admin'), true)) { throw new ApiException('Action unavailable for this task', 409, 409); }
            $context = ['evidence' => $evidence, 'previous_execution_token' => $token];
            if ($action === 'investigate') {
                if ($t['action'] !== 'renew') { throw new ApiException('Read-only renewal investigation required', 409, 409); }
                $p = json_decode($t['payload'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
                $p['investigation_started_at'] = time();
                $s = $db->prepare("UPDATE tasks SET status = 'investigating', execution_token = execution_token + 1, worker_id = NULL, lease_until = NULL, next_retry_at = NULL, last_error = NULL, payload = ?
                    WHERE id = ? AND status = 'failed' AND execution_token = ? AND last_error LIKE 'MANUAL_AUDIT_REQUIRED:%'");
                $s->execute([json_encode($p, JSON_THROW_ON_ERROR), $taskId, $token]);
                if ($s->rowCount() !== 1) { throw new ApiException('Investigation resolution conflict', 409, 409); }
            } elseif ($action === 'cancel_compensate') {
                if ($t['action']==='renew') { throw new ApiException('Renewal compensation requires separate reviewed reconciliation; never terminate the original resource',409,409); }
                $s = $db->prepare('SELECT id FROM commissions WHERE invoice_id = ? FOR UPDATE'); $s->execute([$inv['id']]);
                if ($s->fetch()) { throw new ApiException('Existing commission requires a reviewed reversal before cancellation', 409, 409); }
                $s = $db->prepare("SELECT * FROM payment_records WHERE invoice_id = ? AND status = 'success' ORDER BY id FOR UPDATE"); $s->execute([$inv['id']]);
                $receipts = array_filter($s->fetchAll(), static fn($r) => (json_decode($r['payload'] ?? '{}', true)['settlement_purpose'] ?? null) === 'cloud_consumption');
                if (count($receipts) !== 1) { throw new ApiException('Exactly one verified consumption receipt is required', 409, 409); }
                $r = array_values($receipts)[0];
                if ($r['refund_status'] !== 'none' || $r['currency'] !== 'CNY' || bccomp($r['amount'], $inv['total'], 2) !== 0 || (int)$r['user_id'] !== (int)$instance['user_id']) {
                    throw new ApiException('Receipt cannot be compensated', 409, 409);
                }
                $amount = Money::positive($r['amount']);
                $stmt = $db->prepare("UPDATE invoices SET status = 'refunded', cycle_marker = NULL WHERE id = ? AND status = 'paid'"); $stmt->execute([$inv['id']]);
                if ($stmt->rowCount() !== 1) { throw new ApiException('Refund conflict', 409, 409); }
                (new LedgerService())->wallet($db, (int)$instance['user_id'], (int)$inv['id'], $amount, 'refund_to_balance', 'undelivered_refund:pay_' . $r['id']);
                $stmt = $db->prepare('UPDATE users SET total_spent = total_spent - ? WHERE id = ? AND total_spent >= ?'); $stmt->execute([$amount, $instance['user_id'], $amount]);
                if ($stmt->rowCount() !== 1) { throw new ApiException('Consumption accounting mismatch', 409, 409); }
                $payload = json_decode($r['payload'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
                $payload['refund_method'] = 'to_balance'; $payload['refund_context'] = 'undelivered_permanent_cancel';
                $db->prepare("UPDATE payment_records SET refund_status = 'success', refund_amount = amount, refund_reason = 'Confirmed undeliverable; refunded to wallet', refunded_at = UTC_TIMESTAMP(), payload = ? WHERE id = ?")
                    ->execute([json_encode($payload, JSON_THROW_ON_ERROR), $r['id']]);
                $db->prepare("UPDATE instances SET status = 'terminated' WHERE id = ?")->execute([$iid]);
                $snapshot = json_decode($inv['price_snapshot'], true, 32, JSON_THROW_ON_ERROR);
                if ($snapshot['stock_reserved'] ?? false) { $db->prepare('UPDATE products SET stock = stock + 1 WHERE id = ? AND stock >= 0')->execute([$instance['product_id']]); }
                $db->prepare("UPDATE tasks SET status = 'failed', execution_token = execution_token + 1, worker_id = NULL, lease_until = NULL, next_retry_at = NULL, last_error = 'PERMANENTLY_CANCELLED:COMPENSATED' WHERE instance_id = ? AND (id = ? OR status = 'pending')")->execute([$iid, $taskId]);
                $context['payment_record_id'] = (int)$r['id']; $context['amount'] = $amount;
            } elseif ($action === 'fulfill') {
                $remote = Request::text($input, 'remote_instance_id', 1, 100);
                $ip = $input['ip_address'] ?? null;
                if ($ip !== null && (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP))) { throw new ApiException('Invalid IP address'); }
                if($t['action']==='renew') {
                    if($remote!==$instance['remote_instance_id']){throw new ApiException('Renewal must retain its original resource',409,409);}
                    RenewalService::complete($db,$t);
                } else { InstanceActivationService::applyDelivery($db, $instance, $remote, $ip); }
                $db->prepare("UPDATE tasks SET status = 'success', execution_token = execution_token + 1, worker_id = NULL, lease_until = NULL, next_retry_at = NULL, last_error = NULL WHERE id = ?")->execute([$taskId]);
                $context['remote_instance_id'] = $remote;
            } else {
                $p = json_decode($t['payload'] ?? '{}', true, 32, JSON_THROW_ON_ERROR); unset($p['investigation_started_at']);
                $db->prepare("UPDATE tasks SET status = 'pending', execution_token = execution_token + 1, worker_id = NULL, lease_until = NULL, next_retry_at = NULL, upstream_task_id = NULL, retry_count = 0, last_error = NULL, payload = ? WHERE id = ?")
                    ->execute([json_encode($p, JSON_THROW_ON_ERROR), $taskId]);
            }
            $db->prepare('INSERT INTO admin_audit_logs (admin_id, action, target_type, target_id, context, ip) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$adminId, 'task_' . $action, 'task', $taskId, json_encode($context, JSON_THROW_ON_ERROR), \App\Core\Security::getClientIp()]);
        });
    }
    /** State-based candidates, shared with the read projection; resolve rechecks all locks and financial gates. */
    public static function candidateActions(array $task, array $instance, ?array $invoice, string $role): array
    {
        if ($role !== 'super_admin' || $task['status'] !== 'failed'
            || !str_starts_with($task['last_error'] ?? '', 'MANUAL_AUDIT_REQUIRED:')
            || !in_array($task['action'], ['create','renew'], true) || !$invoice || $invoice['status'] !== 'paid'
            || (int)$invoice['instance_id'] !== (int)$instance['id'] || (int)$invoice['user_id'] !== (int)$instance['user_id']
            || $invoice['type'] !== ($task['action']==='renew' ? 'renew' : 'new')) { return []; }
        if ($task['action']==='create') {
            return $instance['status']==='pending' && $instance['remote_instance_id']===null ? ['fulfill','retry','cancel_compensate'] : [];
        }
        return in_array($instance['status'],['active','suspended'],true) && $instance['remote_instance_id'] ? ['investigate','fulfill','retry'] : [];
    }

}
