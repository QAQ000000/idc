<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\{ApiException, Database, Security};
use App\Plugins\Providers\{CatalogDiscoveryDriverInterface, UpstreamCatalogItem};
use Illuminate\Support\Facades\Cache;

/** Discovery is disposable intelligence; MySQL remains authoritative for imports. */
final class UpstreamCatalogService
{
    private function authorize(int $admin, int $session): void
    {
        $db = Database::getInstance();
        $s = $db->prepare('SELECT role,status,two_factor_secret FROM admins WHERE id=?');
        $s->execute([$admin]);
        $a = $s->fetch();
        if (! $a || $a['status'] !== 'active' || ! empty($a['two_factor_secret']) || AdminAuthService::sessionVersion($db, $admin) !== $session) {
            throw new ApiException('管理员登录已失效，请重新登录', 401, 401);
        }
        if ($a['role'] !== 'super_admin') {
            throw new ApiException('上游目录仅限当前超级管理员查看', 403, 403);
        }
    }

    private function connection(int $id): array
    {
        $s = Database::getInstance()->prepare('SELECT id,name,status,driver,endpoint,credentials FROM providers WHERE id=?');
        $s->execute([$id]);
        $p = $s->fetch();
        if (! $p) {
            throw new ApiException('上游连接不存在', 404, 404);
        }
        if ((int) $p['status'] !== 1) {
            throw new ApiException('上游连接已禁用', 409, 409);
        }
        $p['scope'] = Security::requestFingerprint(json_encode([config('app.env'), config('database.connections.mysql.database'), $id, $p['driver'], $p['endpoint'], $p['credentials']], JSON_THROW_ON_ERROR));

        return $p;
    }

    public function listing(int $admin, int $session, int $id, bool $refresh = false): array
    {
        $this->authorize($admin, $session);
        $p = $this->connection($id);
        try {
            [$driver, $credentials] = (new ProviderService())->resolve($id);
        } catch (\Throwable) {
            throw new ApiException('上游连接配置不可用', 409, 409);
        }
        if (! $driver instanceof CatalogDiscoveryDriverInterface) {
            throw new ApiException('当前驱动不支持目录发现，可继续按PID预览导入', 409, 409);
        }
        $key = 'catalog:p'.$id.':'.$p['scope'];
        try {
            $store = Cache::store('redis');
            if ($refresh && ! $store->add($key.':refresh', true, 30)) {
                throw new ApiException('同一连接30秒内只能强制同步一次，请稍后再试', 429, 429);
            }
            $cached = $store->get($key);
            $hit = ! $refresh && $this->valid($cached);
            if (! $hit) {
                $lock = $store->lock($key.':loading', 60);
                if (! $lock->get()) {
                    throw new ApiException('目录正在同步，请稍后重新读取', 409, 409);
                }
                try {
                    $current = $store->get($key);
                    if (! $refresh && $this->valid($current)) {
                        $cached = $current;
                        $hit = true;
                    } else {
                        $items = $driver->discoverCatalog($credentials);
                        if (count($items) > 5000) {
                            throw new \RuntimeException('Catalog too large');
                        }
                        $seen = [];
                        $rows = [];
                        foreach ($items as $item) {
                            if (! $item instanceof UpstreamCatalogItem || isset($seen[$item->remotePid])) {
                                throw new \RuntimeException('Invalid catalog');
                            }
                            $seen[$item->remotePid] = true;
                            $rows[] = $item->projection();
                        }
                        $cached = ['at' => time(), 'items' => $rows];
                        if (! $store->put($key, $cached, 600)) {
                            throw new \RuntimeException('Cache write failed');
                        }
                    }
                } finally {
                    $lock->release();
                }
            }
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new ApiException('目录同步或缓存不可用，请稍后重试；未修改本地商品', 503, 503);
        }
        // Also reject a revoked session or disabled/replaced connection after a slow remote read.
        $this->authorize($admin, $session);
        $current = $this->connection($id);
        if (! hash_equals($p['scope'], $current['scope'])) {
            throw new ApiException('连接配置已变化，请重新读取目录', 409, 409);
        }
        $s = Database::getInstance()->prepare('SELECT id,remote_pid,status FROM products WHERE provider_id=? ORDER BY id');
        $s->execute([$id]);
        $local = [];
        foreach ($s->fetchAll() as $row) {
            $local[$row['remote_pid']] ??= $row;
        }
        $categories = [];
        $items = [];
        foreach ($cached['items'] as $row) {
            $name = $row['category'];
            $categories[$name] = ($categories[$name] ?? 0) + 1;
            $found = $local[$row['remote_pid']] ?? null;
            $items[] = $row + ['imported' => $found !== null, 'local_product_id' => $found ? (int) $found['id'] : null, 'local_status' => $found['status'] ?? null];
        }
        $groups = [];
        foreach ($categories as $name => $count) {
            $groups[] = ['id' => hash('sha256', (string) $name), 'name' => (string) $name, 'item_count' => $count];
        }

        return ['provider_id' => $id, 'provider_name' => $current['name'], 'cached_at' => gmdate('Y-m-d H:i:s', $cached['at']), 'cache_hit' => $hit, 'categories' => $groups, 'items' => $items];
    }

    private function valid(mixed $value): bool
    {
        if (! is_array($value) || ! is_int($value['at'] ?? null) || $value['at'] < time() - 600 || $value['at'] > time() + 5
            || ! is_array($value['items'] ?? null) || count($value['items']) > 5000) {
            return false;
        }
        try {
            foreach ($value['items'] as $row) {
                if (! is_array($row) || array_keys($row) !== ['remote_pid', 'name', 'category', 'type', 'stock_state', 'stock_qty', 'hidden', 'description', 'observed_at']) {
                    return false;
                }
                new UpstreamCatalogItem($row['remote_pid'], $row['name'], $row['category'], $row['type'], $row['stock_state'], $row['stock_qty'], $row['hidden'], $row['description'], $row['observed_at']);
            }
        } catch (\Throwable) {
            return false;
        }

        return true;
    }
}
