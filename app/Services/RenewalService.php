<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\{ApiException, Database, Money};
use App\Plugins\Providers\RenewalDriverInterface;
use PDO;

final class RenewalService
{
    private static function eligible(array $i): void
    {
        if (! in_array($i['status'], ['active', 'suspended'], true) || ! $i['due_date'] || ! $i['remote_instance_id']
            || ($i['status'] === 'suspended' && $i['suspend_type'] !== 'billing') || InstanceServiceStatusService::renewalBlocked($i)) {
            throw new ApiException('Renewal requires a delivered resource; nonbilling suspension requires operator review', 409, 409);
        }
    }

    private static function available(PDO $db, array $i): void
    {
        self::eligible($i);
        TaskGate::assertClear($db, (int) $i['id']);
        $s = $db->prepare("SELECT action,payload FROM tasks WHERE instance_id=? AND status='pending' FOR UPDATE");
        $s->execute([$i['id']]);
        foreach ($s->fetchAll() as $t) {
            $p = json_decode($t['payload'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
            if ($t['action'] === 'renew' || ($t['action'] === 'terminate' && ($p['termination_reason'] ?? '') !== 'billing_overdue')) {
                throw new ApiException('Renewal conflicts with a queued operation', 409, 409);
            }
        }
    }

    public static function marker(array $i): string
    {
        return 'r:'.$i['id'].':'.str_replace(['-', ':', ' '], '', $i['due_date']);
    }

    public function issue(int $user, int $id, ?string $expectedDue = null): array
    {
        $db = Database::getInstance();
        $s = $db->prepare('SELECT * FROM instances WHERE id=? AND user_id=?');
        $s->execute([$id, $user]);
        $i = $s->fetch();
        if (! $i) {
            throw new ApiException('Instance not found', 404, 404);
        }
        if ($expectedDue !== null) {
            $valid = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $expectedDue)
                ? \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $expectedDue, new \DateTimeZone('UTC')) : false;
            if (! $valid || $valid->format('Y-m-d H:i:s') !== $expectedDue) {
                throw new ApiException('Invalid base_due_date');
            }
        }
        $marker = self::marker(array_replace($i, ['due_date' => $expectedDue ?? $i['due_date'] ?? '']));
        // An existing fixed invoice is usable even while the upstream quote endpoint is unavailable.
        $s = $db->prepare('SELECT id FROM invoices WHERE cycle_marker=? AND user_id=?');
        $s->execute([$marker, $user]);
        if ($existing = $s->fetchColumn()) {
            return (new BillingService())->invoice($user, (int) $existing);
        }
        // Reconcile before a new quote. Returning an existing invoice remains local and never calls upstream.
        (new InstanceServiceStatusService())->reconcile($id, $user);
        $s = $db->prepare('SELECT * FROM instances WHERE id=? AND user_id=?');
        $s->execute([$id, $user]);
        $i = $s->fetch();
        if (! $i) {
            throw new ApiException('Instance not found', 404, 404);
        }
        self::eligible($i);
        if ($expectedDue !== null && $expectedDue !== $i['due_date']) {
            throw new ApiException('Billing period changed; refresh the instance', 409, 409);
        }
        $snapshot = json_decode($i['config_snapshot'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
        try {
            [$driver,$credentials] = (new ProviderService())->resolve((int) $i['provider_id']);
            if (! $driver instanceof RenewalDriverInterface) {
                throw new ApiException('Provider renewal is not enabled', 409, 409);
            }
            $q = $driver->preflightRenewal($i + ['remote_pid' => $snapshot['remote_pid'] ?? 0], $credentials);
        } catch (\Throwable) {
            throw new ApiException('Upstream renewal preflight unavailable; no invoice created', 409, 409);
        }
        if ($q->currency !== 'CNY') {
            throw new ApiException('Unsupported renewal currency', 409, 409);
        }
        $amount = Money::positive($i['override_price'] ?? $i['recurring_price']);
        $upstream = Money::positive($q->amount);

        return Database::transaction(static function (PDO $db) use ($user, $id, $i, $snapshot, $amount, $upstream, $q, $marker): array {
            $s = $db->prepare('SELECT * FROM instances WHERE id=? AND user_id=? FOR UPDATE');
            $s->execute([$id, $user]);
            $current = $s->fetch();
            if (! $current) {
                throw new ApiException('Instance not found', 404, 404);
            }
            $s = $db->prepare('SELECT id FROM invoices WHERE cycle_marker=? AND user_id=? FOR UPDATE');
            $s->execute([$marker, $user]);
            if ($existing = $s->fetchColumn()) {
                return (new BillingService())->invoice($user, (int) $existing);
            }
            if ((int) (\App\Middlewares\AuthMiddleware::user()['id'] ?? 0) === $user) {
                CustomerAccessService::assertActive($db, $user);
            }
            self::available($db, $current);
            foreach (['provider_id', 'product_id', 'remote_instance_id', 'due_date', 'billing_cycle', 'recurring_price', 'override_price', 'config_snapshot'] as $key) {
                if ($current[$key] !== $i[$key]) {
                    throw new ApiException('Instance changed during renewal preflight', 409, 409);
                }
            }
            $s = $db->prepare('SELECT commission_rate FROM products WHERE id=?');
            $s->execute([$i['product_id']]);
            if (bccomp((string) $s->fetchColumn(), '0', 2) !== 0) {
                throw new ApiException('Commission renewal is not enabled', 409, 409);
            }
            $s = $db->prepare("SELECT DATE_FORMAT(DATE_ADD(?, INTERVAL ".self::interval($i['billing_cycle'])."), '%Y-%m-%d %H:%i:%s')");
            $s->execute([$i['due_date']]);
            $target = $s->fetchColumn();
            $p = ['remote_pid' => (int) ($snapshot['remote_pid'] ?? 0), 'provider_id' => (int) $i['provider_id'], 'remote_instance_id' => $i['remote_instance_id'],
                'billing_cycle' => $i['billing_cycle'], 'base_due_date' => $i['due_date'], 'target_due_date' => $target, 'total' => $amount,
                'upstream_total' => $upstream, 'renewal_context' => $q->context];
            $db->prepare("INSERT INTO invoices(user_id,instance_id,type,total,cycle_marker,price_snapshot,quote_expired_at) VALUES(?,?,'renew',?,?,?,NULL)")
                ->execute([$user, $id, $amount, self::marker($i), json_encode($p, JSON_THROW_ON_ERROR)]);

            return (new BillingService())->invoice($user, (int) $db->lastInsertId());
        });
    }

    private static function interval(string $cycle): string
    {
        return match ($cycle) {
            'monthly' => '1 MONTH','annually' => '1 YEAR',default => throw new ApiException('Renewal cycle not enabled', 409, 409)
        };
    }

    /** Caller holds the instance, including when deciding what to do with an actual late receipt. */
    public static function terminationBlocks(PDO $db, array $i): bool
    {
        if ($i['status'] === 'terminated') {
            return true;
        }
        $s = $db->prepare("SELECT status,last_error,payload FROM tasks WHERE instance_id=? AND action='terminate' ORDER BY id FOR UPDATE");
        $s->execute([$i['id']]);
        foreach ($s->fetchAll() as $t) {
            $p = json_decode($t['payload'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
            if (in_array($t['status'], ['running', 'investigating'], true) || ($t['status'] === 'failed' && str_starts_with($t['last_error'] ?? '', 'MANUAL_AUDIT_REQUIRED:'))
                || ($t['status'] === 'pending' && ($p['termination_reason'] ?? '') !== 'billing_overdue')) {
                return true;
            }
        }

        return false;
    }

    public static function assertPayable(PDO $db, array $i, array $invoice): void
    {
        self::available($db, $i);
        $p = json_decode($invoice['price_snapshot'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
        if (($p['base_due_date'] ?? null) !== $i['due_date'] || ($p['remote_instance_id'] ?? null) !== $i['remote_instance_id']
            || ($p['provider_id'] ?? 0) !== (int) $i['provider_id'] || $invoice['cycle_marker'] !== self::marker($i)
            || ($p['billing_cycle'] ?? null) !== $i['billing_cycle'] || bccomp($invoice['total'], $i['override_price'] ?? $i['recurring_price'], 2) !== 0) {
            throw new ApiException('Renewal invoice no longer matches this billing period', 409, 409);
        }
    }

    /** Normal settlement has succeeded, in the same transaction. Local entitlement changes once. */
    public static function applyPayment(PDO $db, array $i, array $invoice): void
    {
        self::assertPayable($db, $i, $invoice);
        $p = json_decode($invoice['price_snapshot'], true, 32, JSON_THROW_ON_ERROR);
        $p['needs_unsuspend'] = $i['status'] === 'suspended' && $i['suspend_type'] === 'billing';
        // Only overdue destruction that has not started may be cancelled, while holding this instance.
        $s = $db->prepare("UPDATE tasks SET status='failed',last_error='CANCELLED_BY_RENEWAL',execution_token=execution_token+1,worker_id=NULL,lease_until=NULL WHERE instance_id=? AND action='terminate' AND status='pending' AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.termination_reason'))='billing_overdue'");
        $s->execute([$i['id']]);
        $s = $db->prepare('UPDATE instances SET due_date=? WHERE id=? AND due_date=?');
        $s->execute([$p['target_due_date'], $i['id'], $p['base_due_date']]);
        if ($s->rowCount() !== 1) {
            throw new ApiException('Renewal period conflict', 409, 409);
        }
        $db->prepare("INSERT INTO tasks(instance_id,invoice_id,idempotency_key,action,payload) VALUES(?,?,?,'renew',?)")
            ->execute([$i['id'], $invoice['id'], 'instance_renew:inv_'.$invoice['id'], json_encode($p, JSON_THROW_ON_ERROR)]);
    }

    /** Caller already holds locks and the Worker lease or audited super-admin authorization. */
    public static function complete(PDO $db, array $task): void
    {
        $s = $db->prepare('SELECT * FROM instances WHERE id=? FOR UPDATE');
        $s->execute([$task['instance_id']]);
        $i = $s->fetch();
        $p = json_decode($task['payload'], true, 32, JSON_THROW_ON_ERROR);
        if (! $i || $i['status'] === 'terminated' || $i['remote_instance_id'] !== ($p['remote_instance_id'] ?? null) || $i['due_date'] !== ($p['target_due_date'] ?? null)) {
            throw new ApiException('Renewal delivery identity or entitlement conflict', 409, 409);
        }
        if ($i['status'] === 'suspended' && $i['suspend_type'] !== 'billing') {
            throw new ApiException('A nonbilling hold requires operator review', 409, 409);
        }
        if (($p['needs_unsuspend'] ?? false) && $i['status'] === 'suspended' && $i['suspend_type'] === 'billing') {
            $db->prepare("UPDATE instances SET status='active',suspend_reason=NULL WHERE id=?")->execute([$i['id']]);
        }
        $snapshot = json_decode($i['config_snapshot'] ?? '{}', true, 64, JSON_THROW_ON_ERROR);
        $remoteTarget = $p['renewal_context']['remote_target_due'] ?? null;
        $snapshot['service_status'] = ['state' => 'active', 'suspension' => 'unknown', 'checked_at' => gmdate('Y-m-d H:i:s'),
            'remote_due_date' => is_int($remoteTarget) ? gmdate('Y-m-d H:i:s', $remoteTarget) : null, 'review_required' => false, 'code' => 'RENEWAL_CONFIRMED'];
        $db->prepare('UPDATE instances SET config_snapshot=? WHERE id=?')->execute([json_encode($snapshot, JSON_THROW_ON_ERROR), $i['id']]);
    }

    public function setAutomatic(int $user, int $id, bool $enabled): void
    {
        Database::transaction(static function (PDO $db) use ($user, $id, $enabled): void {
            $s = $db->prepare('SELECT id FROM instances WHERE id=? AND user_id=? FOR UPDATE');
            $s->execute([$id, $user]);
            if (! $s->fetch()) {
                throw new ApiException('Instance not found', 404, 404);
            }
            CustomerAccessService::assertActive($db, $user);
            $db->prepare('UPDATE instances SET auto_renew=? WHERE id=?')->execute([(int) $enabled, $id]);
        });
    }

    public function generateDue(): int
    {
        $count = 0;
        $cursor = 0;
        $db = Database::getInstance();
        do {
            $s = $db->prepare("SELECT id,user_id FROM instances WHERE id>? AND status IN ('active','suspended') AND due_date<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY) ORDER BY id LIMIT 100");
            $s->execute([$cursor]);
            $rows = $s->fetchAll();
            foreach ($rows as $i) {
                $cursor = (int) $i['id'];
                try {
                    $this->issue((int) $i['user_id'], $cursor);
                    $count++;
                } catch (ApiException $e) {
                    if (! in_array($e->httpStatus, [403, 409], true)) {
                        throw $e;
                    }
                }
            }
        } while (count($rows) === 100);

        return $count;
    }

    public function debitDue(?int $userId = null): int
    {
        if ($userId !== null && $userId < 1) {
            throw new \InvalidArgumentException('Invalid renewal customer');
        }
        $count = 0;
        $cursor = 0;
        $db = Database::getInstance();
        do {
            $scope = $userId === null ? '' : ' AND v.user_id=?';
            $s = $db->prepare("SELECT v.id,v.user_id FROM invoices v JOIN instances i ON i.id=v.instance_id AND i.user_id=v.user_id WHERE v.id>? AND v.type='renew' AND v.status='unpaid' AND i.auto_renew=1 AND i.due_date<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY){$scope} ORDER BY v.id LIMIT 100");
            $s->execute($userId === null ? [$cursor] : [$cursor, $userId]);
            $rows = $s->fetchAll();
            foreach ($rows as $v) {
                $cursor = (int) $v['id'];
                try {
                    (new BillingService())->pay((int) $v['user_id'], $cursor, true);
                    $count++;
                } catch (ApiException $e) {
                    if (! in_array($e->httpStatus, [403, 409], true)) {
                        throw $e;
                    }
                }
            }
        } while (count($rows) === 100);

        return $count;
    }
}
