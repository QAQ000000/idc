<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\{ApiException, Database, Money};
use PDO;

final class CatalogService
{
    public function products(): array
    {
        $rows = Database::getInstance()->query("SELECT p.id, p.name, p.type, p.stock, p.config_cache FROM products p JOIN providers r ON r.id = p.provider_id WHERE p.status = 'active' AND r.status = 1 AND p.commission_rate = 0 ORDER BY p.order_num DESC, p.id DESC")->fetchAll();
        return array_map(static function (array $row): array {
            $cache = json_decode($row['config_cache'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
            unset($row['config_cache']);
            $row['prices'] = $cache['prices'] ?? [];
            return $row;
        }, $rows);
    }

    public function quote(PDO $db, int $id, string $cycle, array $options, bool $lock = false, ?array $preflight = null): array
    {
        $stmt = $db->prepare('SELECT p.*, r.driver, r.status AS provider_status FROM products p JOIN providers r ON r.id = p.provider_id WHERE p.id = ?' . ($lock ? ' FOR UPDATE OF p' : ''));
        $stmt->execute([$id]);
        $product = $stmt->fetch();
        if (!$product || $product['status'] !== 'active' || (int)$product['provider_status'] !== 1) {
            throw new ApiException('Product unavailable', 404, 404);
        }
        if (bccomp($product['commission_rate'], '0', 2) !== 0) {
            throw new ApiException('This product requires a driver or commission engine not yet enabled', 409, 409);
        }
        if ((int)$product['stock'] === 0) {
            throw new ApiException('Out of stock', 409, 1002);
        }
        $cache = json_decode($product['config_cache'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
        if (!in_array($cycle, ['monthly', 'annually'], true) || !isset($cache['prices'][$cycle])) {
            throw new ApiException('Unsupported billing cycle');
        }
        if ($options !== []) {
            throw new ApiException('This fixed-plan product does not accept config options');
        }
        $total = Money::positive($cache['prices'][$cycle]);
        if ($product['driver'] !== 'manual') {
            \App\Plugins\Providers\ProviderRegistry::installed()->get($product['driver']);
            if ($lock && (!$preflight || !hash_equals($preflight['fingerprint'], self::fingerprint($product)) || bccomp($preflight['amount'], $total, 2) !== 0)) {
                throw new ApiException('Product changed after preflight; request a new quote', 409, 409);
            }
        }
        return ['product' => $product, 'total' => $total, 'billing_cycle' => $cycle, 'config_options' => $cache['fixed_config'] ?? []];
    }
    private static function fingerprint(array $product): string
    {
        return hash('sha256', json_encode(array_intersect_key($product, array_flip(['id','provider_id','remote_pid','driver','config_cache','markup_type','markup_value','is_auto_setup','commission_rate'])), JSON_THROW_ON_ERROR));
    }
    /** Read-only network preflight before any transaction/row lock. */
    public function preflight(int $id, string $cycle, array $options): ?array
    {
        $q = $this->quote(Database::getInstance(), $id, $cycle, $options);
        $p = $q['product'];
        if ($p['driver'] === 'manual') { return null; }
        try {
            [$driver, $credentials] = (new ProviderService())->resolve((int)$p['provider_id']);
            $r = $driver->preflightPrice((int)$p['remote_pid'], $cycle, $options, $credentials);
        } catch (\Throwable) { throw new ApiException('Upstream preflight unavailable; no order created', 409, 409); }
        if ($r->currency !== 'CNY' || bccomp(Money::positive($r->amount), $q['total'], 2) !== 0 || $r->config !== $q['config_options']) {
            throw new ApiException('Upstream price or configuration changed; operator review required', 409, 409);
        }
        return ['amount' => $r->amount, 'context' => $r->context, 'fingerprint' => self::fingerprint($p)];
    }

}
