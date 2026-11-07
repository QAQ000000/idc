<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\{ApiException, Database, Money};
use PDO;

final class CatalogService
{
    public function products(): array
    {
        $rows = Database::getInstance()->query("SELECT p.id, p.name, p.type, p.stock, p.config_cache FROM products p JOIN providers r ON r.id = p.provider_id WHERE p.status = 'active' AND r.status = 1 AND r.driver = 'manual' AND p.commission_rate = 0 ORDER BY p.order_num DESC, p.id DESC")->fetchAll();
        return array_map(static function (array $row): array {
            $cache = json_decode($row['config_cache'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
            unset($row['config_cache']);
            $row['prices'] = $cache['prices'] ?? [];
            return $row;
        }, $rows);
    }

    public function quote(PDO $db, int $id, string $cycle, array $options, bool $lock = false): array
    {
        $stmt = $db->prepare('SELECT p.*, r.driver, r.status AS provider_status FROM products p JOIN providers r ON r.id = p.provider_id WHERE p.id = ?' . ($lock ? ' FOR UPDATE OF p' : ''));
        $stmt->execute([$id]);
        $product = $stmt->fetch();
        if (!$product || $product['status'] !== 'active' || (int)$product['provider_status'] !== 1) {
            throw new ApiException('Product unavailable', 404, 404);
        }
        if ($product['driver'] !== 'manual' || bccomp($product['commission_rate'], '0', 2) !== 0) {
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
        return ['product' => $product, 'total' => $total, 'billing_cycle' => $cycle, 'config_options' => $cache['fixed_config'] ?? []];
    }
}
