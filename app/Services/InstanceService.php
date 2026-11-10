<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\{Database, ApiException, Security};

final class InstanceService
{
    public function list(int $user, int $page = 1): array
    {
        $s = Database::getInstance()->prepare('SELECT id FROM instances WHERE user_id = ? ORDER BY id DESC LIMIT 50 OFFSET '.(($page - 1) * 50));
        $s->execute([$user]);

        return array_map(fn ($id) => $this->detail($user, (int) $id), $s->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function detail(int $user, int $id): array
    {
        $db = Database::getInstance();
        $s = $db->prepare('SELECT i.config_snapshot,i.id,i.name,i.status,i.suspend_type,i.remote_instance_id,i.ip_address,i.billing_cycle,i.auto_renew,COALESCE(i.override_price,i.recurring_price) AS recurring_price,i.start_date,i.due_date,p.name AS product_name,p.type,r.driver,r.status AS provider_status FROM instances i JOIN products p ON p.id=i.product_id JOIN providers r ON r.id=i.provider_id WHERE i.id=? AND i.user_id=?');
        $s->execute([$id, $user]);
        $i = $s->fetch();
        if (! $i) {
            throw new ApiException('Instance not found', 404, 404);
        }
        $s = $db->prepare("SELECT id,status FROM invoices WHERE instance_id=? AND type='new' ORDER BY id DESC LIMIT 1");
        $s->execute([$id]);
        $invoice = $s->fetch();
        $s = $db->prepare("SELECT status,last_error FROM tasks WHERE instance_id=? AND action='create' ORDER BY id DESC LIMIT 1");
        $s->execute([$id]);
        $task = $s->fetch();
        $i['invoice_id'] = $invoice ? (int) $invoice['id'] : null;
        $i['delivery_status'] = match (true) {
            in_array($i['status'], ['active', 'suspended'], true) && $i['remote_instance_id'] !== null => 'delivered',
            $invoice && $invoice['status'] === 'refunded' => 'compensated',
            $i['status'] === 'terminated' => 'cancelled',
            $invoice && $invoice['status'] === 'unpaid' => 'awaiting_payment',
            $task && $task['status'] === 'failed' => 'needs_review',
            $task && $task['status'] === 'investigating' => 'investigating',
            default => 'provisioning',
        };
        $s = $db->prepare("SELECT id,status FROM invoices WHERE instance_id=? AND type='renew' ORDER BY id DESC LIMIT 1");
        $s->execute([$id]);
        $renewal = $s->fetch();
        $i['renewal_invoice_id'] = $renewal ? (int) $renewal['id'] : null;
        $i['renewal_status'] = 'none';
        if ($renewal && $renewal['status'] === 'unpaid') {
            $i['renewal_status'] = 'awaiting_payment';
        } elseif ($renewal && $renewal['status'] === 'paid') {
            $s = $db->prepare("SELECT status FROM tasks WHERE invoice_id=? AND action='renew' ORDER BY id DESC LIMIT 1");
            $s->execute([$renewal['id']]);
            $status = $s->fetchColumn();
            $i['renewal_status'] = match ($status) {
                'success' => 'renewed','investigating' => 'investigating','failed' => 'needs_review',default => 'provisioning'
            };
        }
        try {
            $driver = \App\Plugins\Providers\ProviderRegistry::installed()->get($i['driver']);
            $supported = $driver instanceof \App\Plugins\Providers\RenewalDriverInterface;
            $i['service_status_supported'] = $driver instanceof \App\Plugins\Providers\ServiceStatusDriverInterface;
        } catch (\Throwable) {
            $supported = false;
            $i['service_status_supported'] = false;
        }
        $i['renewal_eligible'] = $supported && (int) $i['provider_status'] === 1 && in_array($i['status'], ['active', 'suspended'], true)
            && ($i['status'] !== 'suspended' || $i['suspend_type'] === 'billing') && $i['due_date'] !== null && ! InstanceServiceStatusService::renewalBlocked($i);
        $i += InstanceServiceStatusService::summary($i);
        $snapshot = json_decode($i['config_snapshot'] ?? '{}', true, 64, JSON_THROW_ON_ERROR);
        $i['configuration'] = $snapshot['configuration'] ?? [];
        $i['reinstalled_image'] = $snapshot['delivery']['reinstalled_image'] ?? null;
        unset($i['config_snapshot'],$i['driver'],$i['provider_status']);

        return $i;
    }

    public function credentials(int $user, int $id): array
    {
        return Database::transaction(static function (\PDO $db) use ($user, $id): array {
            $s = $db->prepare('SELECT status,encrypted_password,config_snapshot FROM instances WHERE id=? AND user_id=? FOR UPDATE');
            $s->execute([$id, $user]);
            $row = $s->fetch();
            if (! $row) {
                throw new ApiException('Instance not found', 404, 404);
            }
            if ($row['status'] !== 'active') {
                throw new ApiException('Credentials available after delivery', 409, 409);
            }
            InstanceReinstallService::assertCredentialsAvailable($db, $id);
            CustomerAccessService::assertActive($db, $user);
            $snapshot = json_decode($row['config_snapshot'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);

            return ['username' => $snapshot['delivery']['username'] ?? null, 'password' => Security::decrypt($row['encrypted_password'] ?? ''),
                'verification' => $snapshot['delivery']['credentials_verification'] ?? 'upstream_reported'];
        });
    }
}
