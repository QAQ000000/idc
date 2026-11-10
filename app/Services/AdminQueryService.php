<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\{ApiException, Database, Request};
use PDO;

/** Local read-only customer and resource projections, deliberately without financial or secret fields. */
final class AdminQueryService
{
    private const CUSTOMER = 'u.id,u.email,u.status,u.created_at';

    private const INSTANCE = 'i.id,i.user_id,u.email AS user_email,i.product_id,p.name AS product_name,i.provider_id,r.name AS provider_name,i.name,i.status,i.remote_instance_id,i.ip_address,i.billing_cycle,i.start_date,i.due_date,i.suspend_type,i.auto_renew,i.created_at,i.config_snapshot,r.driver AS status_driver';

    private const JOINS = ' FROM instances i JOIN users u ON u.id=i.user_id JOIN products p ON p.id=i.product_id JOIN providers r ON r.id=i.provider_id';

    private function statusSupported(string $driver): bool
    {
        try {
            return \App\Plugins\Providers\ProviderRegistry::installed()->get($driver) instanceof \App\Plugins\Providers\ServiceStatusDriverInterface;
        } catch (\Throwable) {
            return false;
        }
    }

    private function authorize(int $admin): void
    {
        $s = Database::getInstance()->prepare("SELECT role FROM admins WHERE id=? AND status='active'");
        $s->execute([$admin]);
        if (! in_array($s->fetchColumn(), ['super_admin', 'finance', 'support', 'operator'], true)) {
            throw new ApiException('Active administrator required', 403, 403);
        }
    }

    private function page(array $q): array
    {
        $page = Request::id($q['page'] ?? 1);
        $size = Request::id($q['per_page'] ?? 20);
        if ($page > 100000 || $size > 100) {
            throw new ApiException('Pagination limit exceeded');
        }

        return [$page, $size];
    }

    private function listing(string $columns, string $from, array $args, array $q, string $order): array
    {
        [$page,$size] = $this->page($q);
        $db = Database::getInstance();
        $s = $db->prepare('SELECT COUNT(*)'.$from);
        $s->execute($args);
        $total = (int) $s->fetchColumn();
        $offset = ($page - 1) * $size;
        $s = $db->prepare("SELECT $columns $from ORDER BY $order DESC LIMIT $size OFFSET $offset");
        $s->execute($args);

        return ['items' => $s->fetchAll(), 'total' => $total, 'page' => $page, 'per_page' => $size];
    }

    public function customers(int $admin, array $q): array
    {
        $this->authorize($admin);
        $where = [];
        $args = [];
        if (isset($q['user_id']) && $q['user_id'] !== '') {
            $where[] = 'u.id=?';
            $args[] = Request::id($q['user_id']);
        }
        if (isset($q['email']) && $q['email'] !== '') {
            $where[] = 'u.email=?';
            $args[] = Request::text($q, 'email', 1, 100);
        }

        return $this->listing(self::CUSTOMER, ' FROM users u'.($where ? ' WHERE '.implode(' AND ', $where) : ''), $args, $q, 'u.id');
    }

    public function customer(int $admin, int $id, array $q): array
    {
        $this->authorize($admin);

        return Database::transaction(function (PDO $db) use ($id, $q): array {
            $s = $db->prepare('SELECT '.self::CUSTOMER.' FROM users u WHERE u.id=?');
            $s->execute([$id]);
            $customer = $s->fetch();
            if (! $customer) {
                throw new ApiException('Customer not found', 404, 404);
            }

            return ['customer' => $customer, 'instances' => $this->instanceListing(['user_id' => $id, 'page' => $q['page'] ?? 1, 'per_page' => $q['per_page'] ?? 20])];
        });
    }

    public function instances(int $admin, array $q): array
    {
        $this->authorize($admin);

        return $this->instanceListing($q);
    }

    private function instanceListing(array $q): array
    {
        $where = [];
        $args = [];
        foreach (['instance_id' => 'i.id', 'user_id' => 'i.user_id', 'provider_id' => 'i.provider_id'] as $key => $column) {
            if (isset($q[$key]) && $q[$key] !== '') {
                $where[] = $column.'=?';
                $args[] = Request::id($q[$key]);
            }
        }
        foreach (['ip_address' => 45, 'remote_instance_id' => 100] as $key => $max) {
            if (! isset($q[$key]) || $q[$key] === '') {
                continue;
            }
            $value = Request::text($q, $key, 1, $max);
            if ($key === 'ip_address' && ! filter_var($value, FILTER_VALIDATE_IP)) {
                throw new ApiException('Invalid IP address');
            }
            $where[] = 'i.'.$key.'=?';
            $args[] = $value;
        }
        if (isset($q['name']) && $q['name'] !== '') {
            $where[] = "i.name LIKE ? ESCAPE '!'";
            $args[] = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Request::text($q, 'name', 1, 100)).'%';
        }
        if (isset($q['service_review']) && $q['service_review'] !== '') {
            if ($q['service_review'] !== '1') {
                throw new ApiException('Invalid service review filter');
            }
            $where[] = "JSON_EXTRACT(i.config_snapshot,'$.service_status.review_required')=true";
        }
        $result = $this->listing(self::INSTANCE, self::JOINS.($where ? ' WHERE '.implode(' AND ', $where) : ''), $args, $q, 'i.id');
        foreach ($result['items'] as &$row) {
            $row += InstanceServiceStatusService::summary($row);
            $row['service_status_supported'] = $this->statusSupported($row['status_driver']);
            unset($row['config_snapshot'],$row['status_driver']);
        }unset($row);

        return $result;
    }

    public function instance(int $admin, int $id, array $q): array
    {
        $this->authorize($admin);

        return Database::transaction(function (PDO $db) use ($id, $q): array {
            $s = $db->prepare('SELECT '.self::INSTANCE.self::JOINS.' WHERE i.id=?');
            $s->execute([$id]);
            $instance = $s->fetch();
            if (! $instance) {
                throw new ApiException('Instance not found', 404, 404);
            }
            $invoices = $this->listing('v.id AS invoice_id,v.type,v.status,v.created_at,v.paid_at',
                ' FROM invoices v WHERE v.instance_id=? AND v.user_id=?', [$id, $instance['user_id']], $q, 'v.id');
            $instance += InstanceServiceStatusService::summary($instance);
            $instance['service_status_supported'] = $this->statusSupported($instance['status_driver']);
            unset($instance['config_snapshot'],$instance['status_driver']);

            return ['instance' => $instance, 'invoices' => $invoices];
        });
    }
}
