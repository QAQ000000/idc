<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\{ApiException, Database, Money, Request};
use App\Plugins\Providers\{ConfigurableDriverInterface, ConfigOptions};
use PDO;

/** Local operator import/refresh, reusing an existing account. No new configuration tables. */
final class ProductSnapshotService
{
    public function save(array $input): array
    {
        if (PHP_SAPI!=='cli') { throw new \RuntimeException('CLI only'); }
        $providerId=Request::id($input['provider_id']??null); $remotePid=Request::id($input['remote_pid']??null);
        $name=Request::text($input,'name',1,100); $type=Request::text($input,'type',1,20);
        if (!in_array($type,['cloud','dedicated','cdn','hosting','software'],true)) { throw new ApiException('Invalid product type'); }
        $markupType=$input['markup_type']??'none'; $markupValue=$input['markup_value']??'0.00';
        if (!is_string($markupType) || !is_string($markupValue)) { throw new ApiException('Invalid markup'); }
        Money::markup('1.00',$markupType,$markupValue);
        $cycles=$input['billing_cycles']??['monthly'];
        if (!is_array($cycles) || !$cycles || array_diff($cycles,['monthly','annually']) || count(array_unique($cycles))!==count($cycles)) { throw new ApiException('Invalid billing cycles'); }
        [$driver,$credentials]=(new ProviderService())->resolve($providerId);
        if (!$driver instanceof ConfigurableDriverInterface) { throw new ApiException('Driver does not support configurable snapshots'); }
        $schema=$driver->getFormSchema($remotePid,$credentials); $form=$driver->describeForm($schema);
        $defaults=[]; foreach ($form as $f) { $defaults[$f['id']]=$f['default']; }
        $config=ConfigOptions::validate($form,$input['config_options']??$defaults);
        $prices=[];
        foreach ($cycles as $cycle) {
            $quote=$driver->preflightPrice($remotePid,$cycle,$config,$credentials);
            if ($quote->currency!=='CNY' || $quote->config!==$config || ($quote->context['schema_hash']??'')!==ConfigOptions::hash($form)) { throw new ApiException('Schema changed during import',409,409); }
            $prices[$cycle]=Money::markup($quote->amount,$markupType,$markupValue);
            Money::positive($quote->recurringAmount??$quote->amount);
        }
        $id=isset($input['product_id'])?Request::id($input['product_id']):null;
        return Database::transaction(static function(PDO $db) use($id,$providerId,$remotePid,$name,$type,$markupType,$markupValue,$schema,$prices,$config): array {
            $namespace=bin2hex(random_bytes(20));
            if ($id) {
                $s=$db->prepare('SELECT * FROM products WHERE id=? FOR UPDATE'); $s->execute([$id]);$old=$s->fetch();
                if (!$old || (int)$old['provider_id']!==$providerId || (int)$old['remote_pid']!==$remotePid) { throw new ApiException('Cannot change product resource ownership',409,409); }
                $namespace=json_decode($old['config_cache'],true,64,JSON_THROW_ON_ERROR)['order_namespace'];
            }
            $cache=json_encode(['configurable'=>true,'prices'=>$prices,'default_config'=>$config,'schema'=>$schema,'order_namespace'=>$namespace],JSON_THROW_ON_ERROR);
            if ($id) {
                $db->prepare('UPDATE products SET name=?,type=?,markup_type=?,markup_value=?,config_cache=? WHERE id=?')->execute([$name,$type,$markupType,$markupValue,$cache,$id]);
            } else {
                $db->prepare('INSERT INTO products(provider_id,remote_pid,name,type,markup_type,markup_value,config_cache,is_auto_setup) VALUES(?,?,?,?,?,?,?,1)')->execute([$providerId,$remotePid,$name,$type,$markupType,$markupValue,$cache]);
                $id=(int)$db->lastInsertId();
            }
            return ['product_id'=>$id,'prices'=>$prices];
        });
    }
}
