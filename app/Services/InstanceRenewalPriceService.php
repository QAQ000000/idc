<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\{ApiException,Database,Money,Request,Security};
use PDO;

/** Local future renewal pricing; never changes paid entitlements or calls an upstream. */
final class InstanceRenewalPriceService
{
    private function authorize(PDO $db, int $admin, bool $write = false, bool $lock = false): string
    {
        $s = $db->prepare("SELECT role FROM admins WHERE id=? AND status='active'".($lock ? ' FOR UPDATE' : ''));
        $s->execute([$admin]);
        $role = $s->fetchColumn();
        if (! in_array($role, $write ? ['super_admin'] : ['super_admin', 'finance'], true)) {
            throw new ApiException('Renewal pricing requires financial read access or active super_admin to edit', 403, 403);
        }

        return $role;
    }

    private function instance(PDO $db, int $id): array
    {
        $s = $db->prepare('SELECT * FROM instances WHERE id=? FOR UPDATE');
        $s->execute([$id]);

        return $s->fetch() ?: throw new ApiException('Instance not found', 404, 404);
    }

    private function version(PDO $db, array $i, array $unpaid): string
    {
        // Current read under the instance lock also invalidates an A -> B -> A edit.
        $s = $db->prepare("SELECT id FROM admin_audit_logs WHERE target_type='instance' AND target_id=? AND action IN ('instance_renewal_price','instance_manual_adjustment') ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $s->execute([$i['id']]);

        return Security::requestFingerprint(json_encode([array_intersect_key($i, array_flip(['id', 'user_id', 'provider_id', 'product_id', 'remote_instance_id', 'status', 'suspend_type', 'billing_cycle', 'due_date', 'recurring_price', 'override_price'])), $unpaid, $s->fetchColumn() ?: 0], JSON_THROW_ON_ERROR));
    }

    private function assertEditable(PDO $db, array $i): void
    {
        if (! in_array($i['status'], ['active', 'suspended'], true) || ! $i['remote_instance_id'] || ! $i['due_date']
            || ! in_array($i['billing_cycle'], ['monthly', 'annually'], true)) {
            throw new ApiException('Only delivered monthly or annual instances can change renewal price', 409, 409);
        }
        TaskGate::assertClear($db, (int) $i['id']);
        $s = $db->prepare("SELECT id FROM tasks WHERE instance_id=? AND status='pending' ORDER BY id FOR UPDATE");
        $s->execute([$i['id']]);
        if ($s->fetchColumn()) {
            throw new ApiException('Finish queued instance operations before changing renewal price', 409, 409);
        }
    }

    private function unpaid(PDO $db, array $i): array
    {
        $s = $db->prepare("SELECT id,user_id,total,cycle_marker FROM invoices WHERE instance_id=? AND type='renew' AND status='unpaid' ORDER BY id FOR UPDATE");
        $s->execute([$i['id']]);
        $rows = $s->fetchAll();
        foreach ($rows as &$r) {
            if ((int) $r['user_id'] !== (int) $i['user_id']) {
                throw new ApiException('Renewal invoice ownership mismatch', 409, 409);
            }
            unset($r['user_id']);
        }

        return $rows;
    }

    private static function prices(array $i): array
    {
        return ['frozen_price' => $i['recurring_price'], 'override_price' => $i['override_price'], 'effective_price' => $i['override_price'] ?? $i['recurring_price']];
    }

    /** Caller holds the instance and original invoices; shared by future repricing and manual settlement. */
    public static function cancelUnpaid(PDO $db, array $i, array $unpaid): array
    {
        if (! $db->inTransaction()) {
            throw new \LogicException('Price changes require a transaction');
        }
        $cancelled = [];
        foreach ($unpaid as $v) {
            $s = $db->prepare("UPDATE invoices SET status='cancelled',cycle_marker=NULL WHERE id=? AND instance_id=? AND user_id=? AND type='renew' AND status='unpaid'");
            $s->execute([$v['id'], $i['id'], $i['user_id']]);
            if ($s->rowCount() !== 1) {
                throw new ApiException('Renewal invoice changed; reload and review', 409, 409);
            }
            $cancelled[] = (int) $v['id'];
        }

        return $cancelled;
    }

    public function detail(int $admin, int $id): array
    {
        $this->authorize(Database::getInstance(), $admin);

        return Database::transaction(function (PDO $db) use ($admin, $id): array {
            $i = $this->instance($db, $id);
            $blocked = null;
            try {
                $this->assertEditable($db, $i);
            } catch (ApiException $e) {
                if ($e->httpStatus !== 409) {
                    throw $e;
                }$blocked = $e->getMessage();
            }
            $unpaid = $this->unpaid($db, $i);
            $version = $this->version($db, $i, $unpaid);
            $role = $this->authorize($db, $admin, false, true);
            $s = $db->prepare("SELECT l.id,l.admin_id,a.username,l.context,l.created_at FROM admin_audit_logs l LEFT JOIN admins a ON a.id=l.admin_id WHERE l.target_type='instance' AND l.target_id=? AND l.action='instance_renewal_price' ORDER BY l.id DESC LIMIT 20");
            $s->execute([$id]);
            $audit = $s->fetchAll();
            foreach ($audit as &$row) {
                $row['context'] = array_intersect_key(json_decode($row['context'], true, 32, JSON_THROW_ON_ERROR), array_flip(['evidence', 'before', 'after', 'cancelled_invoice_ids']));
            }

            return ['instance_id' => $id, 'billing_cycle' => $i['billing_cycle']] + self::prices($i) + ['version' => $version, 'editable' => $role === 'super_admin' && $blocked === null, 'blocked_reason' => $blocked, 'unpaid_invoices' => $unpaid, 'audit' => $audit];
        });
    }

    public function update(int $admin, int $id, array $input): array
    {
        $this->authorize(Database::getInstance(), $admin, true);
        foreach (array_keys($input) as $key) {
            if (! in_array($key, ['override_price', 'expected_version', 'evidence'], true)) {
                throw new ApiException('Unsupported renewal price field');
            }
        }
        if (! array_key_exists('override_price', $input)) {
            throw new ApiException('Explicit override_price or null required');
        }
        $price = $input['override_price'] === null ? null : Money::positive($input['override_price']);
        $expected = Request::text($input, 'expected_version', 64, 64);
        $evidence = trim(Request::text($input, 'evidence', 10, 1000));
        if (strlen($evidence) < 10) {
            throw new ApiException('Evidence requires 10–1000 bytes');
        }

        return Database::transaction(function (PDO $db) use ($admin, $id, $price, $expected, $evidence): array {
            $i = $this->instance($db, $id);
            $this->assertEditable($db, $i);
            $unpaid = $this->unpaid($db, $i);
            if (! hash_equals($this->version($db, $i, $unpaid), $expected)) {
                throw new ApiException('Instance renewal price, invoices or period changed; reload and review', 409, 409);
            }
            $this->authorize($db, $admin, true, true);
            if ($price === $i['override_price']) {
                throw new ApiException('Renewal price is unchanged', 409, 409);
            }
            if ($price === null) {
                Money::positive($i['recurring_price']);
            }
            $cancelled = self::cancelUnpaid($db, $i, $unpaid);
            $db->prepare('UPDATE instances SET override_price=? WHERE id=?')->execute([$price, $id]);
            $after = self::prices(array_replace($i, ['override_price' => $price]));
            $context = ['evidence' => $evidence, 'before' => self::prices($i), 'after' => $after, 'cancelled_invoice_ids' => $cancelled];
            $db->prepare("INSERT INTO admin_audit_logs(admin_id,action,target_type,target_id,context,ip) VALUES(?,'instance_renewal_price','instance',?,?,?)")
                ->execute([$admin, $id, json_encode($context, JSON_THROW_ON_ERROR), Security::getClientIp()]);

            return ['instance_id' => $id, 'cancelled_invoice_ids' => $cancelled] + $after;
        });
    }
}
