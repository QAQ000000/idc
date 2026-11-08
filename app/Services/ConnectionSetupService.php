<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\{Database, Money, Security};
use App\Plugins\Providers\ProviderRegistry;
use App\Plugins\Payments\PaymentRegistry;
use PDO;

/** Operator-only local configuration from stdin. Never returns credentials or mutates existing account namespaces. */
final class ConnectionSetupService
{
    public function install(array $input): array
    {
        if (PHP_SAPI !== 'cli') { throw new \RuntimeException('CLI configuration only'); }
        $connections=new ProviderConnectionService();
        $p = $connections->prepare($input['provider']); $product = $input['product']; $payment = $input['payment'];
        $driver = ProviderRegistry::installed()->get($p['driver']); PaymentRegistry::installed()->get($payment['driver']);
        foreach ([$p['endpoint'], $payment['endpoint'], $payment['notify_url'], $payment['return_url']] as $url) {
            if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['https','http'],true)
                || parse_url($url, PHP_URL_USER) || parse_url($url, PHP_URL_PASS)) { throw new \InvalidArgumentException('Invalid connection URL'); }
        }
        if (!in_array($product['type'], ['cloud','dedicated','cdn','hosting','software'],true)) { throw new \InvalidArgumentException('Invalid product type'); }
        $expected = Money::positive($product['monthly_price']);
        $credentials = $p['credentials']; $credentials['endpoint'] = $p['endpoint'];
        $schema = $driver->getFormSchema((int)$product['remote_pid'], $credentials);
        $quote = $driver->preflightPrice((int)$product['remote_pid'], 'monthly', [], $credentials);
        if ($quote->currency !== 'CNY' || bccomp($quote->amount, $expected, 2) !== 0 || $quote->config !== []) { throw new \RuntimeException('Expected fixed product price differs from upstream'); }
        return Database::transaction(static function (PDO $db) use ($p,$product,$payment,$schema,$expected): array {
            $pid=(new ProviderConnectionService())->persist($db,$p);
            $cache = ['prices'=>['monthly'=>$expected], 'fixed_config'=>[], 'schema'=>$schema, 'order_namespace'=>bin2hex(random_bytes(20))];
            $db->prepare("INSERT INTO products(provider_id,remote_pid,name,type,markup_type,config_cache) VALUES(?,?,?,?,'none',?)")->execute([$pid,$product['remote_pid'],$product['name'],$product['type'],json_encode($cache,JSON_THROW_ON_ERROR)]);
            $productId=(int)$db->lastInsertId();
            $public=$payment; unset($public['credentials'],$public['driver']);$public['enabled']=true;
            $db->prepare('INSERT INTO system_settings(`key`,`group`,value,encrypted_credentials) VALUES(?,?,?,?)')
                ->execute(['payment.'.$payment['driver'],'payment',json_encode($public,JSON_THROW_ON_ERROR),Security::encrypt(json_encode($payment['credentials'],JSON_THROW_ON_ERROR))]);
            return ['provider_id'=>$pid,'product_id'=>$productId];
        });
    }
}
