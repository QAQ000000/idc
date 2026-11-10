<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\{ApiException, Database, Money, Security};
use App\Plugins\Providers\{ProviderRegistry, ConfigurableDriverInterface, ConfigOptions, PasswordPolicy};
use App\Plugins\Providers\{StockObservation, UpstreamOutOfStock};
use PDO;

final class CatalogService
{
    public function products(): array
    {
        $rows = Database::getInstance()->query("SELECT p.id, p.name, p.type, p.stock, p.config_cache, p.provider_id, p.remote_pid FROM products p JOIN providers r ON r.id = p.provider_id WHERE p.status = 'active' AND r.status = 1 AND p.commission_rate = 0 ORDER BY p.order_num DESC, p.id DESC")->fetchAll();
        $bindings=[];
        foreach ($rows as $row) { $bindings[(int)$row['id']]=[(int)$row['provider_id'],(int)$row['remote_pid']]; }
        $observations=(new StockObservationService())->readMany($bindings);
        return array_map(static function (array $row) use ($observations): array {
            $cache = json_decode($row['config_cache'] ?? '{}', true, 64, JSON_THROW_ON_ERROR);
            unset($row['config_cache']);
            $row['prices'] = $cache['prices'] ?? [];
            $row['configurable'] = (bool)($cache['configurable'] ?? false);
            $row['upstream_stock']=$observations[(int)$row['id']];
            unset($row['provider_id'],$row['remote_pid']);
            return $row;
        }, $rows);
    }
    private function form(array $product, array $cache): array
    {
        if (!($cache['configurable'] ?? false)) { return []; }
        $driver = ProviderRegistry::installed()->get($product['driver']);
        if (!$driver instanceof ConfigurableDriverInterface) { throw new ApiException('Configuration form unavailable',409,409); }
        try { return $driver->describeForm($cache['schema']); }
        catch (\Throwable) { throw new ApiException('Unsupported product schema; operator review required',409,409); }
    }
    public function configuration(int $id): array
    {
        // Read only the active local snapshot; never publish raw upstream data.
        $db = Database::getInstance();
        $s = $db->prepare('SELECT p.*,r.driver,r.status AS provider_status FROM products p JOIN providers r ON r.id=p.provider_id WHERE p.id=?');
        $s->execute([$id]); $p = $s->fetch();
        if (!$p || $p['status']!=='active' || !(int)$p['provider_status']) { throw new ApiException('Product unavailable',404,404); }
        $cache = json_decode($p['config_cache']??'{}',true,64,JSON_THROW_ON_ERROR);
        $form = $this->form($p,$cache);
        return ['fields'=>$form,'schema_hash'=>ConfigOptions::hash($form),'defaults'=>$cache['default_config']??[]];
    }
    public function quote(PDO $db, int $id, string $cycle, array $options, bool $lock = false, ?array $preflight = null): array
    {
        $stmt = $db->prepare('SELECT p.*, r.driver, r.status AS provider_status FROM products p JOIN providers r ON r.id = p.provider_id WHERE p.id = ?' . ($lock ? ' FOR UPDATE OF p' : ''));
        $stmt->execute([$id]); $product = $stmt->fetch();
        if (!$product || $product['status'] !== 'active' || (int)$product['provider_status'] !== 1) { throw new ApiException('Product unavailable', 404, 404); }
        if (bccomp($product['commission_rate'], '0', 2) !== 0) { throw new ApiException('Commission engine not enabled',409,409); }
        if ((int)$product['stock'] === 0) { throw new ApiException('本地销售配额已用完，暂不可购买',409,1002); }
        $cache = json_decode($product['config_cache'] ?? '{}',true,64,JSON_THROW_ON_ERROR);
        if (!in_array($cycle,['monthly','annually'],true) || !isset($cache['prices'][$cycle])) { throw new ApiException('Unsupported billing cycle'); }
        $configurable = (bool)($cache['configurable'] ?? false);
        $form = $this->form($product,$cache);
        $config = ConfigOptions::validate($form,$options);
        $summary = [];
        foreach ($form as $field) {
            if ($field['type']==='integer') { $summary[]=['label'=>$field['label'],'value'=>$config[$field['id']]]; continue; }
            foreach ($field['choices'] as $choice) {
                if ($choice['value']===$config[$field['id']]) { $summary[]=['label'=>$field['label'],'value'=>$choice['label']]; break; }
            }
        }
        if (!$configurable) { $config = $cache['fixed_config'] ?? []; }
        if ($product['driver']!=='manual') {
            ProviderRegistry::installed()->get($product['driver']);
            if (($lock || $preflight) && (!$preflight || !hash_equals($preflight['fingerprint'], self::fingerprint($product)))) {
                throw new ApiException('Product changed after preflight; request a new quote',409,409);
            }
        }
        $total = $preflight ? Money::markup($preflight['amount'],$product['markup_type'],$product['markup_value']) : Money::positive($cache['prices'][$cycle]);
        $recurring = $preflight ? Money::markup($preflight['recurring'],$product['markup_type'],$product['markup_value']) : $total;
        return ['product'=>$product,'total'=>$total,'recurring_price'=>$recurring,'billing_cycle'=>$cycle,'config_options'=>$config,'configuration'=>$summary,
            'password_policy'=>PasswordPolicy::bounds($preflight['context']??[])];
    }
    private static function fingerprint(array $product): string
    {
        return hash('sha256',json_encode(array_intersect_key($product,array_flip(['id','provider_id','remote_pid','driver','config_cache','markup_type','markup_value','is_auto_setup','commission_rate'])),JSON_THROW_ON_ERROR));
    }
    /** Network preflight stays outside transactions; rechecked against the row under lock. */
    public function preflight(int $id, string $cycle, array $options): ?array
    {
        $q = $this->quote(Database::getInstance(),$id,$cycle,$options); $p = $q['product'];
        if ($p['driver']==='manual') { return null; }
        try {
            [$driver,$credentials] = (new ProviderService())->resolve((int)$p['provider_id']);
            $r = $driver->preflightPrice((int)$p['remote_pid'],$cycle,$q['config_options'],$credentials);
            $amount = Money::positive($r->amount); $recurring = Money::positive($r->recurringAmount ?? $amount);
        } catch (UpstreamOutOfStock) {
            (new StockObservationService())->remember((int)$p['provider_id'],(int)$p['remote_pid'],new StockObservation('sold_out',0));
            throw new ApiException('上游已确认售罄，请稍后重新核价；未创建新订单',409,1003);
        } catch (\Throwable) {
            (new StockObservationService())->remember((int)$p['provider_id'],(int)$p['remote_pid'],null);
            throw new ApiException('上游查询失败，库存暂未确认；请重新核价，未创建新订单',409,1004);
        }
        (new StockObservationService())->remember((int)$p['provider_id'],(int)$p['remote_pid'],$r->stock);
        $cache = json_decode($p['config_cache'],true,64,JSON_THROW_ON_ERROR);
        $schemaChanged = ($cache['configurable']??false) && ($r->context['schema_hash']??'')!==ConfigOptions::hash($this->form($p,$cache));
        if ($r->currency!=='CNY' || $r->config!==$q['config_options'] || $schemaChanged
            || (!($cache['configurable']??false) && bccomp(Money::markup($amount,$p['markup_type'],$p['markup_value']),$q['total'],2)!==0)) {
            throw new ApiException('Upstream price or configuration changed; operator review required',409,409);
        }
        return ['amount'=>$amount,'recurring'=>$recurring,'context'=>$r->context,'fingerprint'=>self::fingerprint($p)];
    }
    private function signature(array $quote, ?array $preflight, int $expires): string
    {
        return Security::requestFingerprint(json_encode([self::fingerprint($quote['product']),$quote['billing_cycle'],$quote['config_options'],$quote['total'],$quote['recurring_price'],$preflight,$expires],JSON_THROW_ON_ERROR));
    }
    public function calculate(int $id, string $cycle, array $options): array
    {
        $preflight=$this->preflight($id,$cycle,$options);
        $q=$this->quote(Database::getInstance(),$id,$cycle,$options,false,$preflight);
        $expires=time()+300;
        $q['quote_token']=$expires.'.'.$this->signature($q,$preflight,$expires);
        $q['quote_expires_at']=gmdate('Y-m-d H:i:s',$expires);
        $p=$q['product'];
        $q['upstream_stock']=(new StockObservationService())->read((int)$p['provider_id'],(int)$p['remote_pid']);
        unset($q['product']); return $q;
    }
    public function assertConfirmed(array $quote, ?array $preflight, mixed $token): void
    {
        $cache=json_decode($quote['product']['config_cache'],true,64,JSON_THROW_ON_ERROR);
        // Old fixed-product API clients remain compatible; all configurable checkouts require confirmation.
        if ($token===null && !($cache['configurable']??false)) { return; }
        if (!is_string($token) || !preg_match('/^([0-9]{10})\.([a-f0-9]{64})$/D',$token,$m)
            || (int)$m[1]<time() || (int)$m[1]>time()+300 || !hash_equals($this->signature($quote,$preflight,(int)$m[1]),$m[2])) {
            throw new ApiException('Quote expired or changed; calculate and confirm again',409,409);
        }
    }
}
