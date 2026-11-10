<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\{Database, Security};
use PDO;

/** Bounded read-only upstream work, followed by one fenced snapshot publication transaction. */
final class ProductRefreshService
{
    public const INTERVAL = 600;

    public const VALID_FOR = 900;

    public const BATCH = 2;

    private function key(int $id): string
    {
        return 'product.refresh.'.$id;
    }

    private function lockName(): string
    {
        return 'jy-refresh:'.substr(hash('sha256', config('database.connections.mysql.database')), 0, 40);
    }

    private function scope(): array
    {
        $raw = config('jianyun.product_refresh_ids', '');
        if (! is_string($raw) || ($raw !== '' && ! preg_match('/^[1-9][0-9]*(,[1-9][0-9]*){0,49}$/D', $raw))) {
            throw new \RuntimeException('Invalid product refresh allowlist');
        }
        $ids = $raw === '' ? [] : array_map('intval', explode(',', $raw));
        if (count($ids) !== count(array_unique($ids)) || array_filter($ids, fn ($id) => $id < 1 || $id > 4294967295)) {
            throw new \RuntimeException('Invalid product refresh allowlist');
        }

        return $ids;
    }

    public function runBatch(): array
    {
        if (PHP_SAPI !== 'cli') {
            throw new \RuntimeException('CLI only');
        }
        $scope = $this->scope();
        if (! $scope) {
            return ['status' => 'disabled', 'items' => []];
        }
        $db = Database::getInstance();
        $s = $db->prepare('SELECT GET_LOCK(?,0)');
        $s->execute([$this->lockName()]);
        if ((int) $s->fetchColumn() !== 1) {
            return ['status' => 'overlap_skipped', 'items' => []];
        }
        try {
            // No Redis lease: the MySQL session owns this disposable refresh mutex until exit.
            // If the session is lost, publication on a replacement session is prohibited below.
            $session = (int) $db->query('SELECT CONNECTION_ID()')->fetchColumn();
            $marks = implode(',', array_fill(0, count($scope), '?'));
            $s = $db->prepare("SELECT p.id FROM products p JOIN providers r ON r.id=p.provider_id WHERE p.id IN ($marks) AND r.status=1 AND p.status<>'retired'");
            $s->execute($scope);
            $candidates = [];
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $meta = $this->metadata($db, (int) $id);
                $attempt = $meta['last_attempt_at'] ?? 0;
                if (! is_int($attempt) || $attempt > time() + 5) {
                    $attempt = 0;
                }
                if ($attempt <= time() - self::INTERVAL) {
                    $candidates[] = ['id' => (int) $id, 'attempt' => $attempt];
                }
            }
            usort($candidates, fn ($a, $b) => [$a['attempt'], $a['id']] <=> [$b['attempt'], $b['id']]);
            $items = [];
            $start = microtime(true);
            foreach (array_slice($candidates, 0, self::BATCH) as $candidate) {
                if (microtime(true) - $start >= 60) {
                    break;
                }
                if ($items) {
                    usleep(1000000);
                } // At most one sequential product start per second.
                $items[] = $this->refresh($db, $candidate['id'], $session);
            }

            return ['status' => 'finished', 'items' => $items];
        } finally {
            try {
                $s = $db->prepare('SELECT RELEASE_LOCK(?)');
                $s->execute([$this->lockName()]);
            } catch (\Throwable) {
            }
        }
    }

    private function read(PDO $db, int $id, bool $lock = false): array
    {
        $s = $db->prepare('SELECT p.*,r.driver,r.status AS provider_status,r.endpoint,r.credentials FROM products p JOIN providers r ON r.id=p.provider_id WHERE p.id=?'.($lock ? ' FOR UPDATE' : ''));
        $s->execute([$id]);
        $p = $s->fetch();
        if (! $p || (int) $p['provider_status'] !== 1 || $p['status'] === 'retired') {
            throw new \RuntimeException('Product unavailable');
        }

        return $p;
    }

    private function version(PDO $db, array $p): string
    {
        $s = $db->prepare("SELECT COALESCE(MAX(id),0) FROM admin_audit_logs WHERE target_type='product' AND target_id=?");
        $s->execute([$p['id']]);
        $fields = array_intersect_key($p, array_flip(['id', 'provider_id', 'remote_pid', 'driver', 'name', 'type', 'status', 'config_cache', 'markup_type', 'markup_value', 'is_auto_setup', 'commission_rate', 'provider_status', 'endpoint', 'credentials']));

        // Stock consumption is not an administrative edit. Other sale facts and ABA audits are fenced.
        return Security::requestFingerprint(ProductSnapshotService::canonical([$fields, $s->fetchColumn()]));
    }

    private function metadata(PDO $db, int $id): array
    {
        $s = $db->prepare('SELECT value FROM system_settings WHERE `key`=?');
        $s->execute([$this->key($id)]);
        $raw = $s->fetchColumn();
        try {
            $v = $raw ? json_decode($raw, true, 32, JSON_THROW_ON_ERROR) : [];

            return is_array($v) ? $v : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function store(PDO $db, int $id, array $meta): void
    {
        $db->prepare("INSERT INTO system_settings(`key`,`group`,value) VALUES(?,'catalog',?) ON DUPLICATE KEY UPDATE value=VALUES(value)")
            ->execute([$this->key($id), json_encode($meta, JSON_THROW_ON_ERROR)]);
    }

    private function fence(PDO $db, int $session): void
    {
        $s = $db->prepare('SELECT IS_USED_LOCK(?)');
        $s->execute([$this->lockName()]);
        if ((int) $s->fetchColumn() !== $session || (int) $db->query('SELECT CONNECTION_ID()')->fetchColumn() !== $session) {
            throw new \RuntimeException('Refresh session lost');
        }
    }

    private function refresh(PDO $db, int $id, int $session): array
    {
        $this->fence($db, $session);
        $started = time();
        $meta = $this->metadata($db, $id);
        $meta['last_attempt_at'] = $started;
        $meta['result'] = 'running';
        $this->store($db, $id, $meta);
        try {
            $old = $this->read($db, $id);
            $version = $this->version($db, $old);
            $cache = json_decode($old['config_cache'] ?? '{}', true, 64, JSON_THROW_ON_ERROR);
            $input = ['product_id' => $id, 'provider_id' => (int) $old['provider_id'], 'remote_pid' => (int) $old['remote_pid'],
                'name' => $old['name'], 'type' => $old['type'], 'markup_type' => $old['markup_type'], 'markup_value' => $old['markup_value'],
                'billing_cycles' => array_keys($cache['prices'] ?? []), 'config_options' => $cache['default_config'] ?? []];
            $prepared = (new ProductSnapshotService())->prepare($input, completeRules: true);
            $result = $this->publish($db, $id, $session, $version, $prepared, $meta);
            logger()->info('Product snapshot refresh finished', ['product_id' => $id, 'result' => $result['result']]);

            return $result;
        } catch (\Throwable) {
            $this->fence($db, $session);
            $meta['result'] = 'failed';
            $meta['finished_at'] = time();
            // Generic persistent reason, never raw transport/schema/credential exceptions.
            $meta['reason'] = 'RULES_UPSTREAM_OR_VERSION_REJECTED';
            $this->store($db, $id, $meta);
            logger()->warning('Product snapshot refresh retained previous snapshot', ['product_id' => $id]);

            return ['product_id' => $id, 'result' => 'failed'];
        }
    }

    private function publish(PDO $db, int $id, int $session, string $version, array $prepared, array $meta): array
    {
        return Database::transaction(function (PDO $tx) use ($db, $id, $session, $version, $prepared, $meta): array {
            if ($tx !== $db) {
                throw new \RuntimeException('Refresh connection changed');
            }
            $this->fence($tx, $session);
            $current = $this->read($tx, $id, true);
            if (! hash_equals($version, $this->version($tx, $current))) {
                throw new \RuntimeException('Concurrent product edit');
            }
            $result = (new ProductSnapshotService())->persist($tx, $prepared);
            $fresh = $this->read($tx, $id);
            $now = time();
            $meta['result'] = $result['changed'] ? 'published' : 'unchanged';
            $meta['finished_at'] = $now;
            $meta['last_success_at'] = $now;
            $meta['valid_until'] = $now + self::VALID_FOR;
            $meta['snapshot_hash'] = hash('sha256', ProductSnapshotService::canonical(json_decode($fresh['config_cache'], true, 64, JSON_THROW_ON_ERROR)));
            unset($meta['reason']);
            $this->store($tx, $id, $meta);

            return ['product_id' => $id, 'result' => $meta['result']];
        });
    }

    /** Read-only freshness projection; supported local previews require a matching valid snapshot. */
    public function observation(int $id, array $cache): array
    {
        $m = $this->metadata(Database::getInstance(), $id);
        $same = is_string($m['snapshot_hash'] ?? null) && hash_equals($m['snapshot_hash'], hash('sha256', ProductSnapshotService::canonical($cache)));
        $fresh = $same && is_int($m['valid_until'] ?? null) && $m['valid_until'] >= time();

        return ['result' => $m['result'] ?? 'never', 'last_attempt_at' => $m['last_attempt_at'] ?? null,
            'last_success_at' => $m['last_success_at'] ?? null, 'valid_until' => $m['valid_until'] ?? null,
            'fresh' => $fresh, 'pricing_mode' => 'online'];
    }
}
