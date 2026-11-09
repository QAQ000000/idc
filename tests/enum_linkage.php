<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use App\Core\{ApiException,Database,Security};
use App\Plugins\Providers\{ConfigOptions,ProviderRegistry};
use App\Services\{CatalogService,BillingService,ProductSnapshotService,OutboxWorker,AdminAuthService,AdminProductService};
if(getenv('APP_ENV')!=='test'||getenv('DB_DATABASE')!=='jianyun_test'){throw new RuntimeException('Isolated DB required');}
$db=Database::getInstance();$checks=0;$upstream=getenv('UPSTREAM_TEST_URL');
function check(bool $ok,string $msg):void{global $checks;if(!$ok)throw new RuntimeException('FAIL ENUM: '.$msg);$checks++;echo "PASS ENUM: $msg\n";}
function reject(callable $fn,string $msg):void{try{$fn();}catch(RuntimeException){check(true,$msg);return;}throw new LogicException('Expected rejection: '.$msg);}
function enumValue(string $sql,array $args=[]):mixed{global $db;$s=$db->prepare($sql);$s->execute($args);return $s->fetchColumn();}
function mode(string $mode):void{global $upstream;file_get_contents($upstream.'/__config_mode?mode='.$mode);}
function state():array{global $upstream;return json_decode(file_get_contents($upstream.'/__state'),true,64,JSON_THROW_ON_ERROR);}
$driver=ProviderRegistry::installed()->get('zjmf');$raw=json_decode(file_get_contents(__DIR__.'/fixtures/zjmf-enum-linkage.json'),true);
$full=json_decode(file_get_contents(__DIR__.'/fixtures/zjmf-product-563.json'),true);
check(count($driver->describeForm($full))===11,'real full product 563 now parses enums and supported independent quantities');
$real=$full;$real['config_groups'][0]['options']=array_values(array_filter($real['config_groups'][0]['options'],fn($o)=>in_array($o['option_type'],[1,2,5,6,8,10,12,13],true)));
$realForm=$driver->describeForm($real);$realDefault=ConfigOptions::defaults($realForm);
check($realDefault[278142]==='1178980' && $realDefault[278141]==='1178975','actual region/CPU condition is preserved in enum-only extraction');
$bad=$realDefault;$bad[278141]='1178976';reject(fn()=>ConfigOptions::validate($realForm,$bad),'actual rule rejects a different CPU despite it being a valid independent enum');
$form=$driver->describeForm($raw);$defaults=ConfigOptions::defaults($form);
check(count($form)===9 && $form[0]['id']==='278142' && $form[1]['id']==='278141','parent-first projection moves upstream child-before-parent order');
check(ConfigOptions::validate($form,$defaults)===$defaults,'defaults satisfy dependency graph');
$selection=$defaults;$selection[278142]='1199990';$selection[278141]='1178976';$selection[278143]='1178982';
check(ConfigOptions::validate($form,$selection)===$selection,'changed region CPU and OS form a valid chain');
$bad=$selection;$bad[278143]='1178981';reject(fn()=>ConfigOptions::validate($form,$bad),'active exclusion rejects forbidden OS');
$bad=$defaults;$bad[278142]='1199990';reject(fn()=>ConfigOptions::validate($form,$bad),'parent change cannot retain obsolete CPU');
$missing=$selection;unset($missing[278142]);reject(fn()=>ConfigOptions::validate($form,$missing),'missing parent cannot bypass negative or positive dependency');
$shuffled=$raw;$shuffled['advanced']=array_reverse($shuffled['advanced']);
foreach($shuffled['advanced'] as &$r){$r['sub_id']=array_reverse($r['sub_id'],true);}unset($r);
check(ConfigOptions::hash($driver->describeForm($shuffled))===ConfigOptions::hash($form),'rule row and sub-ID map ordering do not change semantic hash');
$changed=$raw;$changed['advanced'][1]['sub_id']['1178976']=['qty_minimum'=>'0','qty_maximum'=>'0'];
check(ConfigOptions::hash($driver->describeForm($changed))!==ConfigOptions::hash($form),'rule change with same options changes schema hash');
// Combine two allow sets and exclusions for the same parent, matching the source cart semantics.
$union=$raw;$union['advanced'][]=['id'=>91001,'config_id'=>278141,'sub_id'=>['1178977'=>['qty_minimum'=>'0','qty_maximum'=>'0']],'relation'=>'sneq','type'=>'result','relation_id'=>90001];
$unionForm=$driver->describeForm($union);$cpu=array_values(array_filter($unionForm,fn($f)=>$f['id']==='278141'))[0];
check(array_column(ConfigOptions::available($cpu,$selection),'value')===['1178976'],'exclusions subtract after active allows');
$negative=$raw;$negative['advanced'][2]['relation']='sneq';$negativeForm=$driver->describeForm($negative);
check(ConfigOptions::validate($negativeForm,$selection)===$selection,'negative enum condition uses current selected parent');
$noMatch=$defaults;$noMatch[278141]='1178977';$noMatch[278142]='1199990';$noMatch[278143]='1178981';
check(ConfigOptions::validate($form,$noMatch)===$noMatch,'no active OS restriction keeps original choices');
foreach(['orphan','duplicate','relation','missing_choice','cross_field','range','self','cycle','multiple_parent','no_result','unknown_field','type20','custom','hidden','shape'] as $case){
 $bad=$raw;
 switch($case){
 case 'orphan':$bad['advanced'][1]['relation_id']=999;break;
 case 'duplicate':$bad['advanced'][]=$bad['advanced'][0];break;
 case 'relation':$bad['advanced'][0]['relation']='gte';break;
 case 'missing_choice':$bad['advanced'][0]['sub_id']=['999'=>['qty_minimum'=>0,'qty_maximum'=>0]];break;
 case 'cross_field':$bad['advanced'][0]['sub_id']=['1178975'=>['qty_minimum'=>0,'qty_maximum'=>0]];break;
 case 'range':$bad['advanced'][0]['sub_id']['1178980']['qty_maximum']=1;break;
 case 'self':$bad['advanced'][1]['config_id']=278142;$bad['advanced'][1]['sub_id']=['1178980'=>['qty_minimum'=>0,'qty_maximum'=>0]];break;
 case 'cycle':$bad['advanced'][5]['config_id']=278142;$bad['advanced'][5]['sub_id']=['1178980'=>['qty_minimum'=>0,'qty_maximum'=>0]];break;
 case 'multiple_parent':$bad['advanced'][5]['config_id']=278141;$bad['advanced'][5]['sub_id']=['1178975'=>['qty_minimum'=>0,'qty_maximum'=>0]];break;
 case 'no_result':array_pop($bad['advanced']);break;
 case 'unknown_field':$bad['advanced'][0]['config_id']=999;break;
 case 'type20':$bad['config_groups'][0]['options'][0]['option_type']=20;break;
 case 'custom':$bad['customfields']=[['id'=>1]];break;
 case 'hidden':$bad['config_groups'][0]['options'][0]['hidden']=1;break;
 case 'shape':$bad['advanced']='invalid';break;
 }
 reject(fn()=>$driver->describeForm($bad),'unsupported or malformed '.$case.' fails closed');
}
$independent=json_decode(file_get_contents(__DIR__.'/fixtures/zjmf-product-14.json'),true);$old=$driver->describeForm($independent);
check(count($old)===13 && !isset($old[0]['dependencies']),'independent product 14 retains old public form without extra properties');
$provider=(int)enumValue("SELECT id FROM providers WHERE driver='zjmf' AND endpoint=? LIMIT 1",[$upstream]);
$input=['provider_id'=>$provider,'remote_pid'=>1563,'name'=>'枚举联动隔离协议商品','type'=>'cloud','markup_type'=>'none','markup_value'=>'0.00'];
$aid=(new AdminAuthService)->create(['username'=>'enum-admin','email'=>'enum-admin@example.test','password'=>'EnumAdmin!2026','role'=>'super_admin']);
$adminToken=Security::generateToken(['uid'=>$aid,'sub_type'=>'admin']);
$request=static function(string $method,string $path,?array $data=null,?string $token=null):array{
    $h=curl_init(getenv('TEST_BASE_URL').'/api/v1'.$path);curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_TIMEOUT=>20,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.($token??'')]]);
    if($data!==null)curl_setopt($h,CURLOPT_POSTFIELDS,json_encode($data));
    $r=curl_exec($h);$status=curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);return [$status,json_decode($r,true,64,JSON_THROW_ON_ERROR)];
};
[$status,$preview]=$request('POST','/admin/products/preview',$input,$adminToken);
check($status===200 && $preview['data']['prices']['monthly']==='16.00' && isset($preview['data']['fields'][1]['dependencies']),'existing authenticated admin preview returns dependency fields and live reference quote');
$import=$input+['preview_token'=>$preview['data']['preview_token'],'evidence'=>'ENUM-ACCEPTANCE: confirmed synthetic linked configuration'];
[$status,$result]=$request('POST','/admin/products/import',$import,$adminToken);$pid=(int)($result['data']['product_id']??0);
check($status===200 && enumValue('SELECT status FROM products WHERE id=?',[$pid])==='hidden','confirmed admin import is audited and defaults to hidden');
[$status,$again]=$request('POST','/admin/products/import',$import,$adminToken);
check($status===200 && (int)$again['data']['product_id']===$pid,'same preview replay finds the original imported product');
$detail=(new AdminProductService)->detail($pid);
(new AdminProductService)->update($aid,$pid,['name'=>$detail['name'],'status'=>'active','markup_type'=>'none','markup_value'=>'0.00','expected_version'=>$detail['version'],'evidence'=>'ENUM-ACCEPTANCE: publish isolated synthetic fixture']);
$catalog=new CatalogService;$billing=new BillingService;
$public=$catalog->configuration($pid);
check($public['defaults']===$defaults && str_contains(json_encode($public),'dependencies') && !str_contains(json_encode($public),'upstream_id'),'public configuration includes only safe dependency fields');
$q=$catalog->calculate($pid,'monthly',$defaults);$q2=$catalog->calculate($pid,'monthly',$selection);
check($q['total']==='16.00' && $q['recurring_price']==='14.00' && $q2['total']==='24.00' && $q2['recurring_price']==='22.00','first and recurring prices use upstream configuration quote');
$db->prepare('INSERT INTO users(email,password,balance) VALUES(?,?,1000)')->execute(['enum@example.test',Security::hashPassword('EnumPassword12')]);$user=(int)$db->lastInsertId();
$order=['product_id'=>$pid,'billing_cycle'=>'monthly','hostname'=>'enum-test','password'=>'EnumPassword12','config_options'=>$selection,'checkout_key'=>'enum-checkout-0001','quote_token'=>$q2['quote_token'],'use_balance'=>true];
$balance=enumValue('SELECT balance FROM users WHERE id=?',[$user]);$before=enumValue('SELECT COUNT(*) FROM invoices WHERE user_id=?',[$user]);
$bad=$order;$bad['config_options']=$defaults;$bad['config_options'][278142]='1199990';
$quotes=state()['request_counts']['/cart/get_total']??0;
reject(fn()=>$billing->checkout($user,$bad),'bypassed page illegal combination cannot create an order');
check((state()['request_counts']['/cart/get_total']??0)===$quotes,'local illegal combination is rejected before upstream quote');
[$status]=$request('POST','/orders/checkout',$bad,Security::generateToken(['uid'=>$user]));
check($status===400,'real checkout HTTP route rejects bypassed invalid combination');
check(enumValue('SELECT balance FROM users WHERE id=?',[$user])===$balance && enumValue('SELECT COUNT(*) FROM invoices WHERE user_id=?',[$user])===$before,'invalid configuration leaves money and invoices untouched');
mode('enum_rules');reject(fn()=>$billing->checkout($user,$order),'upstream rule change invalidates old quote even with unchanged price');
mode('normal');$invoice=$billing->checkout($user,$order);
$snapshot=json_decode(enumValue('SELECT price_snapshot FROM invoices WHERE id=?',[$invoice['invoice_id']]),true);
check($snapshot['config_options']===$selection && count($snapshot['driver_context']['configuration_rules'])===2 && $snapshot['driver_context']['schema_hash']===ConfigOptions::hash($form),'selection rule semantics and hash freeze in original invoice');
$payload=json_decode(enumValue('SELECT payload FROM tasks WHERE invoice_id=?',[$invoice['invoice_id']]),true);
check($payload['driver_context']['configuration_rules']===$snapshot['driver_context']['configuration_rules'],'original Outbox carries exact frozen enum rules');
file_get_contents($upstream.'/__mode?mode=drop_after_create');$creates=state()['creates'];
(new OutboxWorker)->runOnce('enum-lost-create');
check(enumValue('SELECT status FROM tasks WHERE invoice_id=?',[$invoice['invoice_id']])==='investigating','lost create response remains an unknown result');
mode('enum_rules');$replay=$order;$replay['quote_token']='expired';
check($billing->checkout($user,$replay)['invoice_id']===$invoice['invoice_id'],'original checkout key recovers order despite changed rules and expired quote');
$db->prepare('UPDATE tasks SET next_retry_at=NULL WHERE invoice_id=?')->execute([$invoice['invoice_id']]);
(new OutboxWorker)->runOnce('enum-investigate');
check(state()['creates']===$creates+1 && enumValue('SELECT status FROM tasks WHERE invoice_id=?',[$invoice['invoice_id']])==='success','read-only recovery uses frozen resource identity without a second purchase');
check(ConfigOptions::canonical(state()['last_config'])===$selection && enumValue('SELECT recurring_price FROM instances WHERE id=?',[$invoice['instance_id']])==='22.00','all selected enum parameters reach fixture delivery with frozen renewal price');
check(enumValue('SELECT balance FROM users WHERE id=?',[$user])===bcsub($balance,'24.00',2) && (int)enumValue('SELECT COUNT(*) FROM tasks WHERE invoice_id=?',[$invoice['invoice_id']])===1,'lost response and replay debit once and create one task');
file_get_contents($upstream.'/__mode?mode=normal');mode('normal');
$new=$order;$new['checkout_key']='enum-rules-change-before-execution';$new['quote_token']=$catalog->calculate($pid,'monthly',$selection)['quote_token'];$newInvoice=$billing->checkout($user,$new);$creates=state()['creates'];mode('enum_rules');
(new OutboxWorker)->runOnce('enum-changed-rules');
check(state()['creates']===$creates && str_starts_with((string)enumValue('SELECT last_error FROM tasks WHERE invoice_id=?',[$newInvoice['invoice_id']]),'MANUAL_AUDIT_REQUIRED:'),'paid rule drift stops before any upstream mutation and alerts for review');
$oldSnapshot=enumValue('SELECT price_snapshot FROM invoices WHERE id=?',[$invoice['invoice_id']]);
$input['product_id']=$pid;mode('enum_rules');(new ProductSnapshotService)->save($input);
check(enumValue('SELECT price_snapshot FROM invoices WHERE id=?',[$invoice['invoice_id']])===$oldSnapshot && enumValue('SELECT recurring_price FROM instances WHERE id=?',[$invoice['instance_id']])==='22.00','refreshing dependency rules never rewrites historic invoice or instance prices');
$stale=$order;$stale['checkout_key']='enum-after-snapshot-refresh';reject(fn()=>$billing->checkout($user,$stale),'old confirmation cannot buy after rule snapshot refresh');
mode('normal');
echo "Enum linkage checks: $checks\n";
