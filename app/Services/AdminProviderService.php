<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\{ApiException,Database,Request,Security};
use App\Plugins\Providers\{ConnectionConfigInterface,ConfigurableDriverInterface,ProviderRegistry};
use PDO;

final class AdminProviderService
{
    private function authorize(int $admin, bool $write = false, ?PDO $db = null): void
    {
        $s = ($db ?? Database::getInstance())->prepare("SELECT role FROM admins WHERE id=? AND status='active'".($db ? ' FOR UPDATE' : ''));
        $s->execute([$admin]);
        $role = $s->fetchColumn();
        if (! in_array($role, $write ? ['super_admin'] : ['super_admin', 'finance', 'support', 'operator'], true)) {
            throw new ApiException('Active administrator permission required', 403, 403);
        }
    }

    private function pagination(array $q, string $key = 'page'): array
    {
        $page = Request::id($q[$key] ?? 1);
        $size = $key === 'audit_page' ? 20 : Request::id($q['per_page'] ?? 20);
        if ($page > 100000 || $size > 100) {
            throw new ApiException('Pagination limit exceeded');
        }

        return [$page, $size];
    }

    private function read(int $id, ?PDO $db = null, bool $lock = false): array
    {
        $s = ($db ?? Database::getInstance())->prepare('SELECT * FROM providers WHERE id=?'.($lock ? ' FOR UPDATE' : ''));
        $s->execute([$id]);
        $p = $s->fetch();
        if (! $p) {
            throw new ApiException('Provider connection not found', 404, 404);
        }

        return $p;
    }

    private function project(array $p): array
    {
        $out = array_intersect_key($p, array_flip(['id', 'name', 'driver', 'status', 'created_at', 'updated_at', 'product_count', 'instance_count']));
        $out['driver_installed'] = false;
        $out['configuration_complete'] = false;
        $out['snapshot_supported'] = false;
        $out['configuration_fields'] = [];
        try {
            $driver = ProviderRegistry::installed()->get($p['driver']);
            $out['driver_installed'] = true;
            $out['snapshot_supported'] = $driver instanceof ConfigurableDriverInterface;
            if ($driver instanceof ConnectionConfigInterface) {
                $credentials = json_decode($p['credentials'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
                $complete = true;
                foreach ($driver->connectionFields() as $field) {
                    $configured = $field['key'] === 'endpoint' ? is_string($p['endpoint']) && $p['endpoint'] !== '' : is_array($credentials) && is_string($credentials[$field['key']] ?? null) && $credentials[$field['key']] !== '';
                    $out['configuration_fields'][] = ['key' => $field['key'], 'label' => $field['label'], 'configured' => $configured];
                    if ($field['required'] && ! $configured) {
                        $complete = false;
                    }
                }
                $out['configuration_complete'] = $complete;
            }
        } catch (\Throwable) {
        } // Installed/configured does not imply successful authentication.

        return $out;
    }

    public function listing(int $admin, array $q): array
    {
        $this->authorize($admin);
        [$page,$size] = $this->pagination($q);
        $where = [];
        $args = [];
        if (isset($q['name']) && $q['name'] !== '') {
            $where[] = "p.name LIKE ? ESCAPE '!'";
            $args[] = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Request::text($q, 'name', 1, 50)).'%';
        }
        if (isset($q['driver']) && $q['driver'] !== '') {
            $where[] = 'p.driver=?';
            $args[] = Request::text($q, 'driver', 1, 50);
        }
        if (isset($q['status']) && $q['status'] !== '') {
            if (! in_array($q['status'], ['0', '1'], true)) {
                throw new ApiException('Invalid provider status');
            }$where[] = 'p.status=?';
            $args[] = $q['status'];
        }
        $from = ' FROM providers p'.($where ? ' WHERE '.implode(' AND ', $where) : '');
        $db = Database::getInstance();
        $s = $db->prepare('SELECT COUNT(*)'.$from);
        $s->execute($args);
        $total = (int) $s->fetchColumn();
        $offset = ($page - 1) * $size;
        $s = $db->prepare('SELECT p.*,(SELECT COUNT(*) FROM products x WHERE x.provider_id=p.id) AS product_count,(SELECT COUNT(*) FROM instances i WHERE i.provider_id=p.id) AS instance_count'.$from." ORDER BY p.id DESC LIMIT $size OFFSET $offset");
        $s->execute($args);

        return ['items' => array_map($this->project(...), $s->fetchAll()), 'total' => $total, 'page' => $page, 'per_page' => $size];
    }

    private function version(array $p, ?PDO $db = null): string
    {
        $s = ($db ?? Database::getInstance())->prepare("SELECT COALESCE(MAX(id),0) FROM admin_audit_logs WHERE target_type='provider' AND target_id=? AND action IN ('provider_create','provider_update')");
        $s->execute([$p['id']]);

        return Security::requestFingerprint(json_encode([$p['id'], $p['name'], $p['driver'], $p['endpoint'], $p['credentials'], $p['status'], $s->fetchColumn()], JSON_THROW_ON_ERROR));
    }

    private function checkVersion(array $p, array $input, ?PDO $db = null): void
    {
        if (! hash_equals($this->version($p, $db), Request::text($input, 'expected_version', 64, 64))) {
            throw new ApiException('Connection changed; reload and review again', 409, 409);
        }
    }

    public function detail(int $admin, int $id, array $q = []): array
    {
        $this->authorize($admin);
        [$page,$size] = $this->pagination($q, 'audit_page');

        return Database::transaction(function (PDO $db) use ($id, $page, $size): array {
            $p = $this->read($id, $db);
            $out = $this->project($p);
            $out['version'] = $this->version($p, $db);
            foreach (['product_count' => 'products', 'instance_count' => 'instances'] as $key => $table) {
                $s = $db->prepare("SELECT COUNT(*) FROM $table WHERE provider_id=?");
                $s->execute([$id]);
                $out[$key] = (int) $s->fetchColumn();
            }
            $s = $db->prepare("SELECT COUNT(*) FROM admin_audit_logs WHERE target_type='provider' AND target_id=?");
            $s->execute([$id]);
            $total = (int) $s->fetchColumn();
            $offset = ($page - 1) * $size;
            $s = $db->prepare("SELECT l.id,l.admin_id,a.username,l.action,l.context,l.created_at FROM admin_audit_logs l LEFT JOIN admins a ON a.id=l.admin_id WHERE l.target_type='provider' AND l.target_id=? ORDER BY l.id DESC LIMIT $size OFFSET $offset");
            $s->execute([$id]);
            $audit = [];
            foreach ($s->fetchAll() as $row) {
                $context = json_decode($row['context'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
                $row['context'] = array_intersect_key($context, array_flip(['evidence', 'before_name', 'after_name', 'remote_pid', 'billing_cycle', 'outcome', 'source']));
                $audit[] = $row;
            }
            $out['audit'] = ['items' => $audit, 'total' => $total, 'page' => $page, 'per_page' => $size];

            return $out;
        });
    }

    public function drivers(int $admin): array
    {
        $this->authorize($admin, true);
        $registry = ProviderRegistry::installed();
        $items = [];
        foreach ($registry->ids() as $id) {
            $driver = $registry->get($id);
            if ($driver instanceof ConnectionConfigInterface) {
                $items[] = ['id' => $id, 'fields' => $driver->connectionFields(), 'snapshot_supported' => $driver instanceof ConfigurableDriverInterface];
            }
        }

        return ['items' => $items, 'version' => Security::requestFingerprint(json_encode($items, JSON_THROW_ON_ERROR))];
    }

    private function evidence(array $input): string
    {
        $e = trim(Request::text($input, 'evidence', 10, 1000));
        if (strlen($e) < 10) {
            throw new ApiException('Evidence requires 10–1000 bytes');
        }

        return $e;
    }

    private function only(array $input, array $keys): void
    {
        if (array_diff(array_keys($input), $keys)) {
            throw new ApiException('Unsupported connection operation field');
        }
    }

    private function audit(PDO $db, int $admin, int $id, string $action, array $context): void
    {
        $db->prepare("INSERT INTO admin_audit_logs(admin_id,action,target_type,target_id,context,ip) VALUES(?,?,'provider',?,?,?)")
            ->execute([$admin, $action, $id, json_encode($context, JSON_THROW_ON_ERROR), Security::getClientIp()]);
    }

    public function create(int $admin, array $input): array
    {
        $this->authorize($admin, true);
        $this->only($input, ['name', 'driver', 'endpoint', 'credentials', 'request_key', 'catalog_version', 'evidence']);
        $evidence = $this->evidence($input);
        $key = Request::text($input, 'request_key', 16, 64);
        if (! preg_match('/^[A-Za-z0-9_-]+$/D', $key)) {
            throw new ApiException('Invalid request key');
        }
        $prepared = (new ProviderConnectionService())->prepare(array_intersect_key($input, array_flip(['name', 'driver', 'endpoint', 'credentials'])));
        $fingerprint = Security::requestFingerprint(json_encode([$prepared, $evidence], JSON_THROW_ON_ERROR));
        $operation = Security::requestFingerprint($admin.':provider:'.$key);
        $catalog = $this->drivers($admin);

        return Database::transaction(function (PDO $db) use ($admin, $input, $prepared, $fingerprint, $operation, $evidence, $catalog): array {
            // Serializes same-admin lost-response retries without a new idempotency table.
            $this->authorize($admin, true, $db);
            $s = $db->prepare("SELECT target_id,JSON_UNQUOTE(JSON_EXTRACT(context,'$.input_fingerprint')) AS input_fingerprint FROM admin_audit_logs WHERE admin_id=? AND target_type='provider' AND action='provider_create' AND JSON_UNQUOTE(JSON_EXTRACT(context,'$.operation_key'))=? ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $s->execute([$admin, $operation]);
            $done = $s->fetch();
            if ($done) {
                if (! hash_equals($done['input_fingerprint'], $fingerprint)) {
                    throw new ApiException('Request key already used with different input', 409, 409);
                }

                return ['provider_id' => (int) $done['target_id']];
            }
            if (! hash_equals($catalog['version'], Request::text($input, 'catalog_version', 64, 64))) {
                throw new ApiException('Driver configuration changed; reload the form', 409, 409);
            }
            $id = (new ProviderConnectionService())->persist($db, $prepared);
            $this->audit($db, $admin, $id, 'provider_create', ['evidence' => $evidence, 'after_name' => $prepared['name'], 'source' => 'admin_web', 'operation_key' => $operation, 'input_fingerprint' => $fingerprint]);

            return ['provider_id' => $id];
        });
    }

    public function update(int $admin, int $id, array $input): array
    {
        $this->authorize($admin, true);
        $this->only($input, ['name', 'expected_version', 'evidence']);
        $evidence = $this->evidence($input);
        $name = trim(Request::text($input, 'name', 1, 50));
        if ($name === '') {
            throw new ApiException('Invalid connection name');
        }

        return Database::transaction(function (PDO $db) use ($admin, $id, $input, $evidence, $name): array {
            $p = $this->read($id, $db, true);
            $this->checkVersion($p, $input, $db);
            $this->authorize($admin, true, $db);
            $db->prepare('UPDATE providers SET name=? WHERE id=?')->execute([$name, $id]);
            $this->audit($db, $admin, $id, 'provider_update', ['evidence' => $evidence, 'before_name' => $p['name'], 'after_name' => $name]);

            return ['provider_id' => $id];
        });
    }

    public function verify(int $admin, int $id, array $input): array
    {
        $this->authorize($admin, true);
        $this->only($input, ['remote_pid', 'billing_cycle', 'expected_version']);
        $p = $this->read($id);
        $this->checkVersion($p, $input);
        $pid = Request::id($input['remote_pid'] ?? null);
        $cycle = Request::text($input, 'billing_cycle', 1, 20);
        if (! in_array($cycle, ['monthly', 'annually'], true)) {
            throw new ApiException('Invalid billing cycle');
        }
        $result = null;
        try {
            $prepared = (new ProductSnapshotService())->prepare(['provider_id' => $id, 'remote_pid' => $pid, 'name' => 'Connection check', 'type' => 'cloud', 'billing_cycles' => [$cycle]]);
            $result = ['verified' => true, 'remote_pid' => $pid, 'billing_cycle' => $cycle, 'field_count' => count($prepared['fields']), 'currency' => 'CNY', 'first_price' => $prepared['prices'][$cycle], 'recurring_price' => $prepared['recurring_prices'][$cycle], 'verified_at' => gmdate('Y-m-d H:i:s')];
        } catch (\Throwable) {
        } // Never include an upstream message, URL, credential or schema in failure output/audit.
        Database::transaction(function (PDO $db) use ($admin, $id, $input, $pid, $cycle, $result): void {
            $p = $this->read($id, $db, true);
            $this->checkVersion($p, $input, $db);
            $this->authorize($admin, true, $db);
            $this->audit($db, $admin, $id, 'provider_verify', ['remote_pid' => $pid, 'billing_cycle' => $cycle, 'outcome' => $result ? 'verified' : 'unavailable']);
        });
        if (! $result) {
            throw new ApiException('Upstream configuration or quote unavailable; no business operation performed', 409, 409);
        }

        return $result;
    }
}
