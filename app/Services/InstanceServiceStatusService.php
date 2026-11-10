<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\{ApiException,Database,Security};
use App\Plugins\Providers\{ProviderRegistry,ServiceStatusDriverInterface,ServiceStatus};
use PDO;

/** Upstream owns suspension/release. This service only reconciles observations, never issues module commands. */
final class InstanceServiceStatusService
{
    private static int $idlePollAfter = 0;

    public static function summary(array $i): array
    {
        $snapshot = json_decode($i['config_snapshot'] ?? '{}', true, 64, JSON_THROW_ON_ERROR);
        $o = $snapshot['service_status'] ?? [];
        $observation = ['state' => 'not_checked', 'suspension' => 'unknown', 'checked_at' => null, 'remote_due_date' => null, 'review_required' => false, 'code' => 'NOT_CHECKED'];
        if (is_array($o)) {
            if (in_array($o['state'] ?? null, ['active', 'suspended', 'terminated', 'cancelled', 'pending', 'unknown'], true)) {
                $observation['state'] = $o['state'];
            }
            if (in_array($o['suspension'] ?? null, ['billing', 'nonbilling', 'unknown'], true)) {
                $observation['suspension'] = $o['suspension'];
            }
            foreach (['checked_at', 'remote_due_date'] as $key) {
                if (is_string($o[$key] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{6})?$/D', $o[$key])) {
                    $observation[$key] = $o[$key];
                }
            }
            $observation['review_required'] = ($o['review_required'] ?? false) === true;
            if (in_array($o['code'] ?? null, ['SERVICE_QUERY_UNAVAILABLE', 'BILLING_DUE', 'NONBILLING_SUSPENSION', 'SUSPENSION_REASON_UNKNOWN', 'SERVICE_ACTIVE', 'REMOTE_SERVICE_REQUIRES_REVIEW', 'RENEWAL_CONFIRMED'], true)) {
                $observation['code'] = $o['code'];
            }
        }
        $entitlement = empty($i['remote_instance_id']) ? 'not_started' : (($i['billing_cycle'] ?? '') === 'onetime' ? 'not_applicable' : (empty($i['due_date']) ? 'not_started' : ($i['due_date'] <= gmdate('Y-m-d H:i:s') ? 'expired' : 'valid')));

        return ['entitlement_state' => $entitlement, 'service_observation' => $observation];
    }

    public static function renewalBlocked(array $i): bool
    {
        return self::summary($i)['service_observation']['review_required'];
    }

    private function authorizeAdmin(?int $admin): void
    {
        if ($admin === null) {
            return;
        }
        $s = Database::getInstance()->prepare("SELECT id FROM admins WHERE id=? AND role='super_admin' AND status='active' FOR SHARE");
        $s->execute([$admin]);
        if (! $s->fetchColumn()) {
            throw new ApiException('Active super_admin required', 403, 403);
        }
    }

    private function read(int $id, ?int $user, bool $lock = false): array
    {
        $s = Database::getInstance()->prepare('SELECT * FROM instances WHERE id=?'.($user === null ? '' : ' AND user_id=?').($lock ? ' FOR UPDATE' : ''));
        $s->execute($user === null ? [$id] : [$id, $user]);

        return $s->fetch() ?: throw new ApiException('Instance not found', 404, 404);
    }

    private function provider(int $id, bool $lock = false): array
    {
        $s = Database::getInstance()->prepare('SELECT id,driver,endpoint,credentials,status FROM providers WHERE id=?'.($lock ? ' FOR SHARE' : ''));
        $s->execute([$id]);

        return $s->fetch() ?: [];
    }

    private function version(array $i): string
    {
        $s = Database::getInstance()->prepare('SELECT id,status,execution_token FROM tasks WHERE instance_id=? ORDER BY id FOR UPDATE');
        $s->execute([$i['id']]);

        return Security::requestFingerprint(json_encode([$i, $s->fetchAll()], JSON_THROW_ON_ERROR));
    }

    private function clear(PDO $db, int $id): void
    {
        TaskGate::assertClear($db, $id);
        $s = $db->prepare("SELECT id FROM tasks WHERE instance_id=? AND status='pending' FOR UPDATE");
        $s->execute([$id]);
        if ($s->fetchColumn()) {
            throw new ApiException('Status reconciliation waits for the existing operation', 409, 409);
        }
    }

    private function result(array $i, bool $applied, string $outcome): array
    {
        return self::summary($i) + ['applied' => $applied, 'outcome' => $outcome, 'local_status' => $i['status'], 'suspend_type' => $i['suspend_type']];
    }

    /** The optional owner is always supplied by customer HTTP, never taken from a request body. */
    public function reconcile(int $id, ?int $user = null, ?int $admin = null): array
    {
        $prepared = Database::transaction(function (PDO $db) use ($id, $user, $admin): array {
            $this->authorizeAdmin($admin);
            $i = $this->read($id, $user, true);
            if (! in_array($i['status'], ['active', 'suspended'], true) || ! $i['remote_instance_id']) {
                return ['result' => $this->result($i, false, 'NOT_DELIVERED')];
            }
            try {
                $this->clear($db, $id);
            } catch (ApiException $e) {
                if ($e->httpStatus !== 409) {
                    throw $e;
                }

                return ['result' => $this->result($i, false, 'OPERATION_BLOCKED')];
            }

            return ['instance' => $i, 'provider' => $this->provider((int) $i['provider_id']), 'version' => $this->version($i)];
        }, 3);
        if (isset($prepared['result'])) {
            return $prepared['result'];
        }
        $i = $prepared['instance'];
        $p = $prepared['provider'];
        try {
            $driver = ProviderRegistry::installed()->get($p['driver'] ?? '');
        } catch (\Throwable) {
            return $this->result($i, false, 'UNSUPPORTED');
        }
        if (! $driver instanceof ServiceStatusDriverInterface) {
            return $this->result($i, false, 'UNSUPPORTED');
        }
        try {
            [$driver,$credentials] = (new ProviderService())->resolve((int) $i['provider_id']);
            $observed = $driver->inspectServiceStatus(InstancePowerService::context($i), $credentials);
        } catch (\Throwable) {
            $observed = new ServiceStatus('unknown');
        }
        $checked = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $alert = null;
        $result = Database::transaction(function (PDO $db) use ($id, $user, $admin, $prepared, $p, $observed, $checked, &$alert): array {
            $this->authorizeAdmin($admin);
            $current = $this->read($id, $user, true);
            if (! hash_equals($prepared['version'], $this->version($current)) || $this->provider((int) $current['provider_id'], true) !== $p) {
                return $this->result($current, false, 'LOCAL_STATE_CHANGED');
            }
            try {
                $this->clear($db, $id);
            } catch (ApiException $e) {
                if ($e->httpStatus !== 409) {
                    throw $e;
                }

                return $this->result($current, false, 'OPERATION_BLOCKED');
            }
            $snapshot = json_decode($current['config_snapshot'] ?? '{}', true, 64, JSON_THROW_ON_ERROR);
            $previous = $snapshot['service_status'] ?? [];
            $review = $observed->state !== 'active' && ! ($observed->state === 'suspended' && $observed->suspension === 'billing');
            $status = $current['status'];
            $type = $current['suspend_type'];
            $reason = $current['suspend_reason'];
            if ($observed->state === 'suspended') {
                // An observed upstream reason cannot overwrite an independent local safety/manual hold.
                $derivedUnknown = $status === 'suspended' && $type === 'manual' && $reason === 'UPSTREAM_SUSPENSION_UNKNOWN';
                if ($status === 'active' || ($status === 'suspended' && $type === 'billing') || $derivedUnknown) {
                    $status = 'suspended';
                    $type = $observed->suspension === 'billing' ? 'billing' : 'manual';
                    $reason = match ($observed->suspension) {
                        'billing' => 'UPSTREAM_BILLING_DUE','nonbilling' => 'UPSTREAM_NONBILLING_SUSPENSION',default => 'UPSTREAM_SUSPENSION_UNKNOWN'
                    };
                }
            }
            if ($status === 'suspended' && $type !== 'billing') {
                $review = true;
            }
            // Active alone never clears a local suspension; renewal confirmation or audited handling must do so.
            if ($status === 'suspended' && $observed->state === 'active') {
                $review = true;
            }
            $snapshot['service_status'] = ['state' => $observed->state, 'suspension' => $observed->suspension, 'remote_due_date' => $observed->dueDate,
                'checked_at' => $checked, 'review_required' => $review, 'code' => $observed->code];
            $db->prepare('UPDATE instances SET status=?,suspend_type=?,suspend_reason=?,config_snapshot=? WHERE id=?')
                ->execute([$status, $type, $reason, json_encode($snapshot, JSON_THROW_ON_ERROR), $id]);
            if ($review && (($previous['review_required'] ?? false) !== true || ($previous['code'] ?? null) !== $observed->code)) {
                $alert = ['instance_id' => $id, 'result_code' => $observed->code];
            }

            return $this->result(array_replace($current, ['status' => $status, 'suspend_type' => $type, 'config_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]), true, 'OBSERVED');
        }, 3);
        if ($alert) {
            logger()->warning('Instance service status requires review', $alert);
        }

        return $result;
    }

    /** Idle Worker only: financial wakeups and existing Outbox always run first. No new queue or timer. */
    public function runOnce(): bool
    {
        if (time() < self::$idlePollAfter) {
            return false;
        }
        $registry = ProviderRegistry::installed();
        $drivers = [];
        foreach ($registry->ids() as $id) {
            if ($registry->get($id) instanceof ServiceStatusDriverInterface) {
                $drivers[] = $id;
            }
        }
        if (! $drivers) {
            self::$idlePollAfter = time() + 15;

            return false;
        }
        $marks = implode(',', array_fill(0, count($drivers), '?'));
        $s = Database::getInstance()->prepare("SELECT i.id FROM instances i JOIN providers p ON p.id=i.provider_id
            WHERE i.status IN ('active','suspended') AND i.remote_instance_id IS NOT NULL AND p.status=1 AND p.driver IN ($marks)
            AND (i.due_date<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY) OR i.status='suspended' OR JSON_EXTRACT(i.config_snapshot,'$.service_status.review_required')=true)
            AND (JSON_EXTRACT(i.config_snapshot,'$.service_status.checked_at') IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(i.config_snapshot,'$.service_status.checked_at'))<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE))
            AND NOT EXISTS(SELECT 1 FROM tasks t WHERE t.instance_id=i.id AND (t.status IN ('pending','running','investigating') OR (t.status='failed' AND t.last_error LIKE 'MANUAL_AUDIT_REQUIRED:%')))
            AND NOT EXISTS(SELECT 1 FROM invoices v WHERE v.instance_id=i.id AND v.status='refund_pending')
            AND NOT EXISTS(SELECT 1 FROM payment_records pr JOIN invoices inv ON inv.id=pr.invoice_id WHERE inv.instance_id=i.id
                AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(pr.payload,'$.settlement_purpose')),'cloud_consumption')='cloud_consumption'
                AND (pr.refund_status='pending' OR JSON_EXTRACT(pr.payload,'$.awaiting_termination')=true))
            ORDER BY COALESCE(JSON_UNQUOTE(JSON_EXTRACT(i.config_snapshot,'$.service_status.checked_at')),''),i.id LIMIT 20");
        $s->execute($drivers);
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if ($this->reconcile((int) $id)['applied']) {
                return true;
            }
        }
        self::$idlePollAfter = time() + 15;

        return false;
    }
}
