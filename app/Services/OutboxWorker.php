<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\{ApiException, Database, Security};
use App\Plugins\Providers\{DriverExecutionResult, RenewalDriverInterface};
use PDO;

final class OutboxWorker
{
    public function __construct(private readonly ?ProviderService $providers = null) {}

    /** One bounded claim. No network operation takes place inside this transaction. */
    public function claim(string $worker): ?array
    {
        if (!preg_match('/^[a-zA-Z0-9:_-]{1,50}$/D', $worker)) { throw new \InvalidArgumentException('Invalid worker id'); }
        $candidates = Database::getInstance()->query("SELECT id, instance_id, action FROM tasks WHERE action IN ('create','renew') AND
            ((status = 'pending' AND (next_retry_at IS NULL OR next_retry_at <= UTC_TIMESTAMP()))
            OR (status IN ('running','investigating') AND (lease_until IS NULL OR lease_until <= UTC_TIMESTAMP())
            AND (next_retry_at IS NULL OR next_retry_at <= UTC_TIMESTAMP()))) ORDER BY id LIMIT 100")->fetchAll();
        foreach ($candidates as $c) {
            try {
                $task = Database::transaction(static function (PDO $db) use ($c, $worker): ?array {
                    $s = $db->prepare('SELECT * FROM instances WHERE id = ? FOR UPDATE SKIP LOCKED');
                    $s->execute([$c['instance_id']]); $instance = $s->fetch();
                    if (!$instance || ($c['action']==='create' && ($instance['status'] !== 'pending' || $instance['remote_instance_id'] !== null))
                        || ($c['action']==='renew' && (!in_array($instance['status'],['active','suspended'],true) || !$instance['remote_instance_id']))) { return null; }
                    TaskGate::assertClear($db, (int)$instance['id'], (int)$c['id']);
                    $s = $db->prepare("SELECT *, lease_until > UTC_TIMESTAMP() AS leased, next_retry_at > UTC_TIMESTAMP() AS retry_delayed FROM tasks WHERE id = ? FOR UPDATE");
                    $s->execute([$c['id']]); $t = $s->fetch();
                    if (!$t || !in_array($t['status'], ['pending','running','investigating'], true) || $t['retry_delayed']
                        || ($t['status'] !== 'pending' && $t['leased'])) { return null; }
                    $s = $db->prepare("SELECT status FROM invoices WHERE id = ? AND instance_id = ? AND user_id = ? AND type = ? FOR UPDATE");
                    $s->execute([$t['invoice_id'], $instance['id'], $instance['user_id'], $t['action']==='renew'?'renew':'new']);
                    if ($s->fetchColumn() !== 'paid') { return null; }
                    $mode = $t['status'] === 'pending' ? 'running' : 'investigating';
                    $payload = json_decode($t['payload'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
                    if ($mode === 'investigating') { $payload['investigation_started_at'] ??= time(); }
                    $db->prepare("UPDATE tasks SET status = ?, worker_id = ?, execution_token = execution_token + 1,
                        lease_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE), next_retry_at = NULL, payload = ? WHERE id = ?")
                        ->execute([$mode, $worker, json_encode($payload, JSON_THROW_ON_ERROR), $t['id']]);
                    $t['status'] = $mode; $t['worker_id'] = $worker; $t['execution_token'] = (int)$t['execution_token'] + 1;
                    $t['payload'] = $payload; $t['instance'] = $instance;
                    return $t;
                });
                if ($task) { return $task; }
            } catch (ApiException $e) { if ($e->httpStatus !== 409) { throw $e; } }
        }
        return null;
    }

    public function runOnce(string $worker): bool
    {
        $t = $this->claim($worker);
        if (!$t) { return false; }
        if ($t['action']==='create' && !($t['payload']['is_auto_setup'] ?? false)) {
            $this->finish($t, new DriverExecutionResult('unknown', code: 'NON_QUERYABLE'));
            return true;
        }
        try {
            [$driver, $credentials] = ($this->providers ?? new ProviderService())->resolve((int)$t['instance']['provider_id']);
            $order = $t['payload'] + [
                'idempotency_key' => $t['idempotency_key'], 'instance_id' => (int)$t['instance_id'],
                'hostname' => $t['instance']['name'], 'billing_cycle' => $t['instance']['billing_cycle'],
                'operation_id' => $t['upstream_task_id'],
            ];
            if ($t['action']==='renew') {
                if (!$driver instanceof RenewalDriverInterface) { $result=new DriverExecutionResult('unknown',code:'NON_QUERYABLE'); }
                elseif ($t['status']==='investigating') { $result=$driver->queryRenewal($order,$credentials); }
                elseif ($t['instance']['status']==='suspended' && $t['instance']['suspend_type']!=='billing') { $result=new DriverExecutionResult('rejected',code:'NONBILLING_SUSPENSION'); }
                else { $result=$driver->renew($order,$credentials); }
            } elseif ($t['status'] === 'investigating') {
                $result = $driver->getQueryAuthority() === 'NON_QUERYABLE'
                    ? new DriverExecutionResult('unknown', code: 'NON_QUERYABLE') : $driver->queryOrder($order, $credentials);
            } else {
                $order['password'] = Security::decrypt($t['instance']['encrypted_password'] ?? '') ?? '';
                $result = $driver->create($order, $credentials);
            }
        } catch (\Throwable) {
            // Transport errors, malformed responses and driver exceptions are NOT evidence of nonexecution.
            $result = new DriverExecutionResult('unknown', code: 'DRIVER_OR_TRANSPORT_ERROR');
        }
        try { $this->finish($t, $result); }
        catch (\Throwable $e) {
            // Preserve the claim for crash recovery. Never put a possibly executed task back in pending.
            logger()->error('Outbox result requires recovery', ['task_id' => (int)$t['id'], 'exception_type'=>$e::class]);
        }
        return true;
    }

    public function heartbeat(int $id, string $worker, int $token): bool
    {
        return Database::transaction(static function (PDO $db) use ($id, $worker, $token): bool {
            $t = self::lockOwned($db, $id, $worker, $token);
            $db->prepare('UPDATE tasks SET lease_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE id = ?')->execute([$t['id']]);
            return true;
        });
    }

    public static function lockOwned(PDO $db, int $id, string $worker, int $token): array
    {
        $s = $db->prepare('SELECT instance_id FROM tasks WHERE id = ?'); $s->execute([$id]); $instanceId = $s->fetchColumn();
        if (!$instanceId) { throw new ApiException('Task not found', 404, 404); }
        $s = $db->prepare('SELECT id FROM instances WHERE id = ? FOR UPDATE'); $s->execute([$instanceId]);
        TaskGate::assertClear($db, (int)$instanceId, $id);
        $s = $db->prepare('SELECT *, lease_until > UTC_TIMESTAMP() AS leased FROM tasks WHERE id = ? FOR UPDATE');
        $s->execute([$id]); $t = $s->fetch();
        if (!in_array($t['status'], ['running','investigating'], true) || $t['worker_id'] !== $worker
            || (int)$t['execution_token'] !== $token || !$t['leased']) {
            throw new ApiException('Stale or expired task execution', 409, 409);
        }
        return $t;
    }

    public function finish(array $claim, DriverExecutionResult $result): void
    {
        Database::transaction(static function (PDO $db) use ($claim, $result): void {
            $t = self::lockOwned($db, (int)$claim['id'], $claim['worker_id'], (int)$claim['execution_token']);
            if ($result->status === 'succeeded') {
                if ($t['action']==='renew') {
                    $p=json_decode($t['payload'],true,32,JSON_THROW_ON_ERROR);
                    if ($result->remoteInstanceId!==($p['remote_instance_id']??null)) { throw new ApiException('Renewal delivery resource mismatch',409,409); }
                    RenewalService::complete($db,$t);
                    $db->prepare("UPDATE tasks SET status='success',lease_until=NULL,worker_id=NULL,next_retry_at=NULL,last_error=NULL,upstream_task_id=COALESCE(?,upstream_task_id) WHERE id=?")->execute([$result->operationId,$t['id']]);
                    return;
                }
                if ($result->username !== null || $result->password !== null) {
                    $s = $db->prepare('SELECT config_snapshot FROM instances WHERE id = ?'); $s->execute([$t['instance_id']]);
                    $snapshot = json_decode($s->fetchColumn() ?: '{}', true, 32, JSON_THROW_ON_ERROR);
                    $snapshot['delivery'] = ['username' => $result->username];
                    $db->prepare('UPDATE instances SET config_snapshot = ?, encrypted_password = COALESCE(?, encrypted_password) WHERE id = ?')->execute([json_encode($snapshot, JSON_THROW_ON_ERROR), $result->password === null ? null : Security::encrypt($result->password), $t['instance_id']]);
                }
                (new InstanceActivationService())->completeCreate((int)$t['id'], $t['worker_id'], (int)$t['execution_token'], $result->remoteInstanceId ?? '', $result->ipAddress);
                return;
            }
            $p = json_decode($t['payload'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
            $retry = (int)$t['retry_count'];
            $next = null; $status = 'investigating'; $error = $result->code;
            if ($result->status === 'not_executed') {
                // Driver guarantees no asset, no debit, and no request that can execute later.
                if ($retry < (int)$t['max_retries']) {
                    $status = 'pending'; $next = gmdate('Y-m-d H:i:s', time() + [300,900,1800][min($retry,2)]); $retry++;
                } else { $status = 'failed'; $error = 'MANUAL_AUDIT_REQUIRED:RETRIES_EXHAUSTED'; }
            } elseif ($result->status === 'rejected') {
                $status = 'failed'; $error = 'MANUAL_AUDIT_REQUIRED:' . $error;
            } else {
                $p['investigation_started_at'] ??= time();
                if ($result->code === 'NON_QUERYABLE' || time() - $p['investigation_started_at'] >= 3600) {
                    $status = 'failed'; $error = 'MANUAL_AUDIT_REQUIRED:' . $error;
                } else { $next = gmdate('Y-m-d H:i:s', time() + 60); }
            }
            $db->prepare('UPDATE tasks SET status = ?, worker_id = NULL, lease_until = NULL, next_retry_at = ?, retry_count = ?, last_error = ?, payload = ?, upstream_task_id = COALESCE(?, upstream_task_id) WHERE id = ?')
                ->execute([$status, $next, $retry, $error, json_encode($p, JSON_THROW_ON_ERROR), $result->operationId, $t['id']]);
            if ($status === 'failed') { logger()->critical('Outbox manual review required', ['task_id' => (int)$t['id']]); }
        });
    }
}
