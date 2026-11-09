<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use App\Core\{Database,Security};
use App\Plugins\Providers\{ConfigOptions,ProviderRegistry};
use App\Services\{CatalogService,BillingService,ProductSnapshotService,OutboxWorker,AdminAuthService,AdminProductService};
if(getenv('APP_ENV')!=='test'||getenv('DB_DATABASE')!=='jianyun_test'){throw new RuntimeException('Isolated DB required');}
$db=Database::getInstance();$checks=0;$upstream=getenv('UPSTREAM_TEST_URL');
function check(bool $ok,string $msg):void{global $checks;if(!$ok)throw new RuntimeException('FAIL QUANTITY: '.$msg);$checks++;echo "PASS QUANTITY: $msg\n";}
function reject(callable $fn,string $msg):void{try{$fn();}catch(RuntimeException){check(true,$msg);return;}throw new LogicException('Expected rejection: '.$msg);}
function quantityValue(string $sql,array $args=[]):mixed{global $db;$s=$db->prepare($sql);$s->execute($args);return $s->fetchColumn();}
function mode(string $mode):void{global $upstream;file_get_contents($upstream.'/__config_mode?mode='.$mode);}
function state():array{global $upstream;return json_decode(file_get_contents($upstream.'/__state'),true,64,JSON_THROW_ON_ERROR);}
function requests():int{global $upstream;return json_decode(file_get_contents($upstream.'/__request_counts'),true)['/cart/get_total']??0;}
function field(array $form,string $id):array{return array_values(array_filter($form,fn($f)=>$f['id']===$id))[0];}
$driver=ProviderRegistry::installed()->get('zjmf');
$full=json_decode(file_get_contents(__DIR__.'/fixtures/zjmf-product-563.json'),true,64,JSON_THROW_ON_ERROR);
$form=$driver->describeForm($full);$defaults=ConfigOptions::defaults($form);$ip=field($form,'278144');$bw=field($form,'278145');
check(count($form)===11 && $defaults[278144]==='1' && $defaults[278145]==='50','full real 563 shape projects two quantities alongside enum dependency');
check($ip['type']==='integer' && $ip['minimum']===1 && $ip['maximum']===4 && $ip['step']===1 && $ip['choices']===[],'type15 uses integer bounds not enum sub-IDs');
check($bw['minimum']===50 && $bw['maximum']===1000 && $bw['step']===10 && $bw['step_base']===0 && $bw['allow_endpoints'],'type18 retains actual bandwidth bounds step and endpoint convention');
check($bw['ranges']===[['id'=>'1179012','minimum'=>50,'maximum'=>50],['id'=>'1179013','minimum'=>51,'maximum'=>1000]],'pricing intervals keep source identities separate from final quantities');
foreach([['1','50'],['2','60'],['4','1000']] as [$i,$b]){$o=$defaults;$o[278144]=$i;$o[278145]=$b;check(ConfigOptions::validate($form,$o)===$o,'accept integer interval and step boundaries '.$i.'/'.$b);}
foreach(['0','5','1179010','-1','1.0','1.5','1e0','+1',' 1','',1.0,true,[],null] as $bad){$o=$defaults;$o[278144]=$bad;reject(fn()=>ConfigOptions::validate($form,$o),'reject invalid IP quantity '.json_encode($bad));}
foreach(['49','51','59','999','1001','1179013'] as $bad){$o=$defaults;$o[278145]=$bad;reject(fn()=>ConfigOptions::validate($form,$o),'reject bandwidth bounds step or sub-ID '.$bad);}
$o=$defaults;$o[278144]=4;$o[278145]=1000;check(ConfigOptions::validate($form,$o)[278144]==='4','integer JSON values canonicalize as strings');
$o=$defaults;unset($o[278145]);reject(fn()=>ConfigOptions::validate($form,$o),'missing quantity fails');
$o=$defaults;$o[999]='1';reject(fn()=>ConfigOptions::validate($form,$o),'extra quantity fails');
$o=$defaults;$o[278141]='1178976';reject(fn()=>ConfigOptions::validate($form,$o),'integer support does not loosen existing enum restrictions');
$nonAligned=$full;$q=&$nonAligned['config_groups'][0]['options'][4];$q['qty_minimum']=51;$q['qty_maximum']=999;$q['sub'][0]['qty_minimum']=51;$q['sub'][0]['qty_maximum']=51;$q['sub'][1]['qty_minimum']=52;$q['sub'][1]['qty_maximum']=999;unset($q);
$f=$driver->describeForm($nonAligned);$d=ConfigOptions::defaults($f);
foreach(['51','60','999'] as $n){$o=$d;$o[278145]=$n;check(ConfigOptions::validate($f,$o)===$o,'zero-based step accepts explicit endpoints '.$n);}
$o=$d;$o[278145]='61';reject(fn()=>ConfigOptions::validate($f,$o),'step is not offset from minimum51');
$zero=$full;$zero['config_groups'][0]['options'][3]['qty_minimum']=0;$zero['config_groups'][0]['options'][3]['sub'][0]['qty_minimum']=0;$zero['config_groups'][0]['options'][3]['qty_stage']=0;
$f=$driver->describeForm($zero);check(ConfigOptions::defaults($f)[278144]==='0' && field($f,'278144')['step']===1,'explicit zero quantity allowed and upstream step0 means1');
$reordered=$full;$reordered['config_groups'][0]['options'][4]['sub']=array_reverse($reordered['config_groups'][0]['options'][4]['sub']);
check(ConfigOptions::hash($driver->describeForm($reordered))===ConfigOptions::hash($form),'reordered nonoverlapping tier map preserves semantic fingerprint');
foreach(['decimal_min','negative_max','missing_step','decimal_step','range_gap','range_overlap','range_reversed','range_outside','duplicate_sub','hidden_sub','empty_sub','unsupported_type','quantity_condition','quantity_result'] as $case){
 $bad=$full;$q=&$bad['config_groups'][0]['options'][4];
 switch($case){
 case 'decimal_min':$q['qty_minimum']='50.5';break;
 case 'negative_max':$q['qty_maximum']=-1;break;
 case 'missing_step':unset($q['qty_stage']);break;
 case 'decimal_step':$q['qty_stage']='0.5';break;
 case 'range_gap':$q['sub'][1]['qty_minimum']=60;break;
 case 'range_overlap':$q['sub'][1]['qty_minimum']=50;break;
 case 'range_reversed':$q['sub'][1]['qty_minimum']=1001;break;
 case 'range_outside':$q['sub'][1]['qty_maximum']=1001;break;
 case 'duplicate_sub':$q['sub'][1]['id']=$q['sub'][0]['id'];break;
 case 'hidden_sub':$q['sub'][1]['hidden']=1;break;
 case 'empty_sub':$q['sub']=[];break;
 case 'unsupported_type':$q['option_type']=14;break;
 case 'quantity_condition':$bad['advanced'][0]['config_id']=278145;$bad['advanced'][0]['sub_id']=['1179012'=>['qty_minimum'=>50,'qty_maximum'=>50]];break;
 case 'quantity_result':$bad['advanced'][1]['config_id']=278145;$bad['advanced'][1]['sub_id']=['1179012'=>['qty_minimum'=>50,'qty_maximum'=>50]];break;
 }unset($q);
 reject(fn()=>$driver->describeForm($bad),'unsupported or malformed '.$case.' fails closed');
}
foreach(['qty_stage','qty_maximum'] as $key){$bad=$full;$bad['config_groups'][0]['options'][4][$key]=$key==='qty_stage'?20:1010;if($key==='qty_maximum')$bad['config_groups'][0]['options'][4]['sub'][1]['qty_maximum']=1010;check(ConfigOptions::hash($driver->describeForm($bad))!==ConfigOptions::hash($form),'quantity '.$key.' changes fingerprint');}
$provider=(int)quantityValue("SELECT id FROM providers WHERE driver='zjmf' AND endpoint=? LIMIT 1",[$upstream]);
$input=['provider_id'=>$provider,'remote_pid'=>2563,'name'=>'整数数量隔离协议商品','type'=>'cloud','markup_type'=>'none','markup_value'=>'0.00'];
$aid=(new AdminAuthService)->create(['username'=>'quantity-admin','email'=>'quantity-admin@example.test','password'=>'QuantityAdmin!2026','role'=>'super_admin']);
$token=Security::generateToken(['uid'=>$aid,'sub_type'=>'admin']);
$request=static function(string $method,string $path,?array $data=null,?string $token=null):array{
 $h=curl_init(getenv('TEST_BASE_URL').'/api/v1'.$path);curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.($token??'')]]);
 if($data!==null)curl_setopt($h,CURLOPT_POSTFIELDS,json_encode($data));$r=curl_exec($h);$status=curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);return [$status,json_decode($r,true,64,JSON_THROW_ON_ERROR)];
};
[$status,$p]=$request('POST','/admin/products/preview',$input,$token);
check($status===200 && field($p['data']['fields'],'278145')['type']==='integer' && $p['data']['prices']['monthly']==='32.00','authenticated admin previews quantity defaults and upstream first price');
$import=$input+['preview_token'=>$p['data']['preview_token'],'evidence'=>'QUANTITY-ACCEPTANCE: confirmed synthetic schema and quote'];
[$status,$result]=$request('POST','/admin/products/import',$import,$token);$pid=(int)$result['data']['product_id'];
check($status===200 && quantityValue('SELECT status FROM products WHERE id=?',[$pid])==='hidden','quantity preview import is audited and hidden');
[$status,$again]=$request('POST','/admin/products/import',$import,$token);check($status===200 && (int)$again['data']['product_id']===$pid,'same import confirmation restores original product');
[$status]=$request('POST','/admin/products/preview',array_replace($input,['remote_pid'=>563]),$token);check($status===409,'full563 zero-stock fixture rejects sale preview despite parse support');
$detail=(new AdminProductService)->detail($pid);(new AdminProductService)->update($aid,$pid,['name'=>$detail['name'],'status'=>'active','markup_type'=>'none','markup_value'=>'0.00','expected_version'=>$detail['version'],'evidence'=>'QUANTITY-ACCEPTANCE: publish isolated fixture']);
$catalog=new CatalogService;$billing=new BillingService;$public=$catalog->configuration($pid);$selection=$public['defaults'];$selection[278144]='2';$selection[278145]='60';
check(count($public['fields'])===11 && $public['defaults'][278145]==='50','customer projection retains quantities alongside linked enums');
$q=$catalog->calculate($pid,'monthly',$selection);$q0=$catalog->calculate($pid,'monthly',$public['defaults']);
check($q['total']==='40.00' && $q['recurring_price']==='35.00' && $q0['total']==='32.00' && $q0['recurring_price']==='30.00','first and renewal prices both come from fixture upstream after quantity changes');
check(in_array(['label'=>'带宽','value'=>'60'],$q['configuration'],true),'frozen customer summary shows quantity60 not pricing sub-ID');
$db->prepare('INSERT INTO users(email,password,balance) VALUES(?,?,1000)')->execute(['quantity@example.test',Security::hashPassword('QuantityPassword12')]);$user=(int)$db->lastInsertId();$userToken=Security::generateToken(['uid'=>$user]);
$order=['product_id'=>$pid,'billing_cycle'=>'monthly','hostname'=>'quantity-test','password'=>'QuantityPassword12','config_options'=>$selection,'checkout_key'=>'quantity-checkout-0001','quote_token'=>$q['quote_token'],'use_balance'=>true];
$balance=quantityValue('SELECT balance FROM users WHERE id=?',[$user]);$quotes=requests();
$bad=$order;$bad['config_options'][278145]='51';[$status]=$request('POST','/orders/checkout',$bad,$userToken);
check($status===400 && requests()===$quotes,'HTTP bypass rejects off-step quantity before upstream quote');
check(quantityValue('SELECT balance FROM users WHERE id=?',[$user])===$balance && (int)quantityValue('SELECT COUNT(*) FROM invoices WHERE user_id=?',[$user])===0,'rejected quantity cannot mutate money or invoices');
$bad=$order;$bad['config_options']=$public['defaults'];reject(fn()=>$billing->checkout($user,$bad),'different quantity cannot use old quote token');
mode('quantity_step');reject(fn()=>$billing->checkout($user,$order),'changed step invalidates old quote even for still-valid quantity60');mode('normal');
mode('quantity_ranges');reject(fn()=>$billing->checkout($user,$order),'changed tier interval invalidates old quote without price change');mode('normal');
$invoice=$billing->checkout($user,$order);$snapshot=json_decode(quantityValue('SELECT price_snapshot FROM invoices WHERE id=?',[$invoice['invoice_id']]),true);
check(ConfigOptions::canonical($snapshot['config_options'])===$selection && count($snapshot['driver_context']['configuration_quantities'])===2 && field($snapshot['driver_context']['configuration_quantities'],'278145')['ranges']==$bw['ranges'],'invoice freezes numeric values limits step and tier identities');
$payload=json_decode(quantityValue('SELECT payload FROM tasks WHERE invoice_id=?',[$invoice['invoice_id']]),true);check($payload['driver_context']['configuration_quantities']===$snapshot['driver_context']['configuration_quantities'],'original Outbox retains exact quantity rules');
file_get_contents($upstream.'/__mode?mode=drop_after_create');$creates=state()['creates'];(new OutboxWorker)->runOnce('quantity-lost-create');
check(quantityValue('SELECT status FROM tasks WHERE invoice_id=?',[$invoice['invoice_id']])==='investigating','lost create response retains investigation gate');
mode('quantity_step');$replay=$order;$replay['quote_token']='expired';check($billing->checkout($user,$replay)['invoice_id']===$invoice['invoice_id'],'same request key restores original order despite changed quantity schema');
$changed=$replay;$changed['config_options'][278144]='3';reject(fn()=>$billing->checkout($user,$changed),'same request key with changed quantity cannot become another purchase');
$db->prepare('UPDATE tasks SET next_retry_at=NULL WHERE invoice_id=?')->execute([$invoice['invoice_id']]);(new OutboxWorker)->runOnce('quantity-investigate');
check(state()['creates']===$creates+1 && quantityValue('SELECT status FROM tasks WHERE invoice_id=?',[$invoice['invoice_id']])==='success','read-only investigation recovers original resource without repurchase after drift');
check(ConfigOptions::canonical(state()['last_config'])===$selection && state()['last_qty']===['1'],'settle sends quantity values under plural configoptions and exactly one instance');
check(quantityValue('SELECT recurring_price FROM instances WHERE id=?',[$invoice['instance_id']])==='35.00' && quantityValue('SELECT balance FROM users WHERE id=?',[$user])===bcsub($balance,'40.00',2) && (int)quantityValue('SELECT COUNT(*) FROM tasks WHERE invoice_id=?',[$invoice['invoice_id']])===1,'lost response and replay debit once freeze renewal price and create one task');
mode('normal');file_get_contents($upstream.'/__mode?mode=normal');
$new=$order;$new['checkout_key']='quantity-rules-before-execution';$new['quote_token']=$catalog->calculate($pid,'monthly',$selection)['quote_token'];$next=$billing->checkout($user,$new);mode('quantity_ranges');$creates=state()['creates'];(new OutboxWorker)->runOnce('quantity-changed-ranges');
check(state()['creates']===$creates && str_starts_with((string)quantityValue('SELECT last_error FROM tasks WHERE invoice_id=?',[$next['invoice_id']]),'MANUAL_AUDIT_REQUIRED:'),'paid interval drift stops before first upstream purchase');
$historic=quantityValue('SELECT price_snapshot FROM invoices WHERE id=?',[$invoice['invoice_id']]);$input['product_id']=$pid;(new ProductSnapshotService)->save($input);
check(quantityValue('SELECT price_snapshot FROM invoices WHERE id=?',[$invoice['invoice_id']])===$historic && quantityValue('SELECT recurring_price FROM instances WHERE id=?',[$invoice['instance_id']])==='35.00','refresh cannot rewrite historic quantity snapshot or frozen renewal price');
$stale=$order;$stale['checkout_key']='quantity-after-refresh';reject(fn()=>$billing->checkout($user,$stale),'snapshot refresh requires new quote confirmation');
mode('normal');echo "Integer quantity checks: $checks\n";
