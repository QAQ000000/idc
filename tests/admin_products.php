<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
set_exception_handler(static function(Throwable $e):never{fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
use App\Core\{Database,Security};
use App\Services\{AdminAuthService,AuthService,BillingService,CatalogService,OutboxWorker,RenewalService,ProductSnapshotService};
if(getenv('APP_ENV')!=='test' || getenv('DB_DATABASE')!=='jianyun_test' || !preg_match('#^http://127\.0\.0\.1:[0-9]+$#D',getenv('TEST_BASE_URL')?:'')){throw new RuntimeException('Isolated environment required');}
$db=Database::getInstance();$checks=0;
function check(bool $v,string $m):void{global $checks;if(!$v){throw new RuntimeException('FAIL product admin: '.$m);}$checks++;echo "PASS product admin: $m\n";}
function pv(string $sql,array $a=[]):mixed{global $db;$s=$db->prepare($sql);$s->execute($a);return $s->fetchColumn();}
function req(string $method,string $path,?array $body=null,?string $token=null):array{
 $h=curl_init(getenv('TEST_BASE_URL').'/api/v1'.$path);curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>array_filter(['Content-Type: application/json',$token?'Authorization: Bearer '.$token:null])]);if($body!==null){curl_setopt($h,CURLOPT_POSTFIELDS,json_encode($body));}$raw=curl_exec($h);$code=curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);return [$code,json_decode($raw,true,64,JSON_THROW_ON_ERROR)];
}
function detail(int $id):array{global $admin;[$s,$r]=req('GET','/admin/products/'.$id,null,$admin);if($s!==200){throw new RuntimeException('detail failed: '.json_encode($r));}return $r['data'];}
function edit(int $id,array $changes=[]):array{$p=detail($id);return array_replace(['name'=>$p['name'],'status'=>$p['status'],'markup_type'=>$p['markup_type'],'markup_value'=>$p['markup_value'],'expected_version'=>$p['version']],$changes);}
function fixture(string $path):array{return json_decode(file_get_contents(getenv('UPSTREAM_TEST_URL').$path),true,64,JSON_THROW_ON_ERROR);}
function concurrent(string $method,string $path,array $body,string $token,int $count=4):array{
 $multi=curl_multi_init();$handles=[];
 for($i=0;$i<$count;$i++){$h=curl_init(getenv('TEST_BASE_URL').'/api/v1'.$path);curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$token],CURLOPT_POSTFIELDS=>json_encode($body)]);$handles[]=$h;curl_multi_add_handle($multi,$h);}
 do{curl_multi_exec($multi,$running);if($running){curl_multi_select($multi,1);}}while($running);
 $results=[];foreach($handles as $h){$results[]=[curl_getinfo($h,CURLINFO_RESPONSE_CODE),json_decode(curl_multi_getcontent($h),true,64,JSON_THROW_ON_ERROR)];curl_multi_remove_handle($multi,$h);curl_close($h);}curl_multi_close($multi);return $results;
}
function preview(array $input,?int $id=null):array{global $admin;[$s,$r]=req('POST',$id?'/admin/products/'.$id.'/snapshot/preview':'/admin/products/preview',$input,$admin);if($s!==200){throw new RuntimeException('preview failed: '.json_encode($r));}return $r['data'];}
function snapshot(array $i,array $p,?int $id=null):array{global $admin;return req('POST',$id?'/admin/products/'.$id.'/snapshot':'/admin/products/import',$i+['preview_token'=>$p['preview_token'],'evidence'=>'OPS-SNAPSHOT: verified upstream schema and reference quote'],$admin);}
$auth=new AdminAuthService();$aid=$auth->create(['username'=>'product-test-admin','email'=>'product-test-admin@example.test','password'=>'ProductAdmin!2026','role'=>'super_admin']);$admin=Security::generateToken(['uid'=>$aid,'sub_type'=>'admin']);
$rid=$auth->create(['username'=>'product-test-reader','email'=>'product-test-reader@example.test','password'=>'ProductReader!2026','role'=>'operator']);$reader=Security::generateToken(['uid'=>$rid,'sub_type'=>'admin']);
$user=(new AuthService())->register(['email'=>'product-customer@example.test','password'=>'ProductClient!2026']);$uid=(int)$user['user']['id'];$client=$user['token'];(new App\Services\DemoService())->recharge($uid,'1000.00','product-admin');
$db->prepare("INSERT INTO providers(name,driver,endpoint,credentials) SELECT 'Product admin isolated upstream',driver,endpoint,credentials FROM providers WHERE endpoint=? AND driver='zjmf' ORDER BY id LIMIT 1")->execute([getenv('UPSTREAM_TEST_URL')]);$provider=(int)$db->lastInsertId();
$input=['provider_id'=>$provider,'remote_pid'=>14,'name'=>'Admin selected cloud fixture','type'=>'cloud','markup_type'=>'none','markup_value'=>'0.00','billing_cycles'=>['monthly']];
fixture('/__config_mode?mode=normal');fixture('/__renew_mode?mode=normal');
check(req('GET','/admin/products')[0]===401 && req('GET','/admin/products',null,$client)[0]===401,'customer and anonymous identities cannot inspect catalog administration');
check(req('GET','/admin/products',null,$reader)[0]===200,'enabled operator can read product list');
check(req('POST','/admin/products/preview',$input,$reader)[0]===403 && req('POST','/admin/products/import',$input,$reader)[0]===403,'read-only role cannot preview upstream or import');
$providers=req('GET','/admin/providers',null,$admin)[1]['data'];
check(!str_contains(json_encode($providers),'endpoint') && !str_contains(json_encode($providers),'credentials') && !str_contains(json_encode($providers),'isolated-api-key'),'provider picker contains no connection secrets');
foreach(['page[]=1','per_page=101','status=invalid','provider_id=-1','name[]=x'] as $q){check(req('GET','/admin/products?'.$q,null,$admin)[0]===400,'invalid filter rejected: '.$q);}
$pre=preview($input);
check(req('POST','/admin/products/import',$input+['preview_token'=>$pre['preview_token']],$admin)[0]===400,'import still requires evidence independently of routine edits');
check(count($pre['fields'])===13 && $pre['prices']['monthly']==='16.00' && $pre['recurring_prices']['monthly']==='14.00','import preview projects selectable form and separate first/recurring prices');
check((int)pv('SELECT COUNT(*) FROM products WHERE provider_id=?',[$provider])===0,'read-only preview does not import or purchase');
check(!str_contains(json_encode($pre),'order_namespace') && !str_contains(json_encode($pre),'credentials') && !str_contains(json_encode($pre),'pricings'),'preview hides raw schema and credentials');
check(snapshot(array_replace($input,['name'=>'tampered name']),$pre)[0]===409,'preview is bound to intended product input');
$bad=$pre;$bad['preview_token']='1000000000.'.str_repeat('a',64);check(snapshot($input,$bad)[0]===409,'expired snapshot confirmation rejected');
fixture('/__config_mode?mode=price');check(snapshot($input,$pre)[0]===409,'upstream price change between preview and import requires new confirmation');fixture('/__config_mode?mode=normal');
[$s,$r]=snapshot($input,$pre);$pid=$r['data']['product_id']??0;check($s===200 && $pid>0,'confirmed import creates product from existing provider');
$p=detail($pid);check($p['status']==='hidden' && $p['remote_pid']===14 && $p['provider_id']===$provider,'import defaults to hidden and retains resource binding');
check(snapshot($input,$pre)[1]['data']['product_id']===$pid && (int)pv("SELECT COUNT(*) FROM admin_audit_logs WHERE target_type='product' AND target_id=? AND action='product_import'",[$pid])===1,'lost-response import replay returns same product and one audit');
$another=preview($input);check(snapshot($input,$another)[0]===409 || snapshot($input,$another)[1]['data']['product_id']===$pid,'duplicate upstream product cannot create a second local catalog entry');
check((int)pv('SELECT COUNT(*) FROM products WHERE provider_id=? AND remote_pid=14',[$provider])===1,'remote binding is not duplicated');
check(!str_contains(json_encode($p),'order_namespace') && !str_contains(json_encode($p),'encrypted') && !str_contains(json_encode($p),'isolated-api-key'),'detail exposes only safe local product fields');
check(req('GET','/products/'.$pid.'/config')[0]===404,'hidden product cannot be configured by customer');
$edit=edit($pid,['status'=>'active','markup_type'=>'fixed','markup_value'=>'2.00']);
check(req('PATCH','/admin/products/'.$pid,$edit,$reader)[0]===403,'operator cannot change sale state through HTTP');
check(req('PATCH','/admin/products/'.$pid,$edit+['provider_id'=>999],$admin)[0]===400,'editor rejects rebinding and arbitrary columns');
check(req('PATCH','/admin/products/'.$pid,array_replace($edit,['markup_value'=>'-1']),$admin)[0]===400,'negative markup rejected');
check(req('PATCH','/admin/products/'.$pid,array_replace($edit,['markup_value'=>2]),$admin)[0]===400,'money must use decimal strings');
check(req('PATCH','/admin/products/'.$pid,$edit,$admin)[0]===200,'super administrator can publish and set fixed markup without evidence');
$p=detail($pid);check($p['prices']['monthly']==='18.00' && $p['recurring_prices']['monthly']==='16.00','reference prices recomputed from original cost, preserving setup fee difference');
check($p['audit']['items'][0]['action']==='product_update' && !array_key_exists('evidence',$p['audit']['items'][0]['context']) && $p['audit']['items'][0]['context']['before']['status']==='hidden' && $p['audit']['items'][0]['context']['after']['status']==='active' && (int)$p['audit']['items'][0]['admin_id']===$aid && $p['audit']['items'][0]['created_at']!=='' && filter_var(pv('SELECT ip FROM admin_audit_logs WHERE id=?',[$p['audit']['items'][0]['id']]),FILTER_VALIDATE_IP)!==false,'automatic audit retains actor, time, IP and before/after without fabricated evidence');
check($p['audit']['items'][1]['context']['evidence']==='OPS-SNAPSHOT: verified upstream schema and reference quote','existing audit evidence remains readable');
check(req('PATCH','/admin/products/'.$pid,$edit,$admin)[0]===409,'stale edit is rejected even on repeated payload');
check(req('GET','/admin/products?provider_id='.$provider.'&status=active&per_page=1',null,$admin)[1]['data']['total']===1,'provider/status filtering and pagination work');
check(req('GET','/admin/products?provider_id='.$provider.'&page=2&per_page=1',null,$admin)[1]['data']['items']===[],'product page beyond results is empty');
$catalog=new CatalogService();$bill=new BillingService();$options=$p['defaults'];
$q=$catalog->calculate($pid,'monthly',$options);
$order=['product_id'=>$pid,'billing_cycle'=>'monthly','hostname'=>'product-host','password'=>'ProductRoot2026','config_options'=>$options,'quote_token'=>$q['quote_token'],'checkout_key'=>'admin-product-order-0001'];
$pending=$bill->checkout($uid,$order);
$oldInvoice=pv('SELECT price_snapshot FROM invoices WHERE id=?',[$pending['invoice_id']]);$oldInstance=pv('SELECT config_snapshot FROM instances WHERE id=?',[$pending['instance_id']]);
$edit=edit($pid,['name'=>'Retitled new sales only','markup_type'=>'percent','markup_value'=>'50.00','evidence'=>'Legacy client optional note']);
check(req('PATCH','/admin/products/'.$pid,$edit,$admin)[0]===200 && detail($pid)['prices']['monthly']==='24.00','percentage markup uses stored cost rather than marked-up retail');
check(detail($pid)['audit']['items'][0]['context']['evidence']==='Legacy client optional note','older clients can still supply an optional audit note');
check(pv('SELECT price_snapshot FROM invoices WHERE id=?',[$pending['invoice_id']])===$oldInvoice && pv('SELECT config_snapshot FROM instances WHERE id=?',[$pending['instance_id']])===$oldInstance,'editing name/markup never modifies historical invoice or resource snapshot');
check(pv('SELECT recurring_price FROM instances WHERE id=?',[$pending['instance_id']])==='16.00','catalog adjustment leaves frozen instance renewal price intact');
check(req('POST','/orders/checkout',array_replace($order,['checkout_key'=>'stale-product-order-0002']),$client)[0]===409,'catalog markup change invalidates previously confirmed new quote');
check(req('PATCH','/admin/products/'.$pid,edit($pid,['status'=>'retired']),$admin)[0]===200,'product can be retired without touching existing invoices');
check(req('GET','/products/'.$pid.'/config')[0]===404 && req('POST','/products/'.$pid.'/calculate',['billing_cycle'=>'monthly','config_options'=>$options])[0]===404,'retired product blocks public configuration and new quotes');
check(req('POST','/invoices/'.$pending['invoice_id'].'/pay',['gateway'=>'balance'],$client)[0]===200,'pre-existing frozen unpaid invoice remains payable after delisting');
// Claim only this suite's paid work; never use a business database.
$db->prepare("UPDATE tasks t JOIN instances i ON i.id=t.instance_id SET t.status='failed',t.last_error='PERMANENTLY_CANCELLED:OTHER_TEST_FINISHED',t.lease_until=NULL WHERE i.user_id<>? AND t.status IN ('pending','running','investigating')")->execute([$uid]);
fixture('/__mode?mode=normal');fixture('/__config_mode?mode=normal');$worker=new OutboxWorker();$worker->runOnce('delisted-paid-delivery');
check(pv('SELECT status FROM instances WHERE id=?',[$pending['instance_id']])==='active','paid delivery still executes frozen upstream configuration after retirement and markup change');
$renew=(new RenewalService())->issue($uid,(int)$pending['instance_id']);
check($renew['total']==='16.00','retired product renewal bills original resource price');
check(req('POST','/invoices/'.$renew['invoice_id'].'/pay',['gateway'=>'balance'],$client)[0]===200,'retired product renewal payment remains available');
$worker->runOnce('delisted-renewal');check(pv('SELECT status FROM tasks WHERE invoice_id=?',[$renew['invoice_id']])==='success','retired product upstream renewal completes through original resource');
// Refresh a changed default configuration while preserving all historical financial facts.
$beforeInvoice=pv('SELECT price_snapshot FROM invoices WHERE id=?',[$pending['invoice_id']]);$beforeInstance=pv('SELECT config_snapshot FROM instances WHERE id=?',[$pending['instance_id']]);$namespace=json_decode(pv('SELECT config_cache FROM products WHERE id=?',[$pid]),true)['order_namespace'];
$changedOptions=$options;$changedOptions['276853']='1173031';$refresh=['expected_version'=>detail($pid)['version'],'billing_cycles'=>['monthly','annually'],'config_options'=>$changedOptions];$rp=preview($refresh,$pid);
check(req('POST','/admin/products/'.$pid.'/snapshot',$refresh+['preview_token'=>$rp['preview_token']],$admin)[0]===400,'manual snapshot refresh still requires evidence');
check($rp['prices']['monthly']==='36.00' && $rp['recurring_prices']['monthly']==='33.00','refresh preview calculates selected CPU and separate recurring markup');
check(snapshot($refresh,$rp,$pid)[0]===200,'confirmed refresh updates one local JSON schema snapshot');
$p=detail($pid);check($p['status']==='retired' && $p['defaults']['276853']==='1173031' && count($p['billing_cycles'])===2,'refresh keeps sale state and installs selected default configuration/cycles');
check(json_decode(pv('SELECT config_cache FROM products WHERE id=?',[$pid]),true)['order_namespace']===$namespace,'refresh preserves stable order namespace for existing recovery identities');
check(pv('SELECT price_snapshot FROM invoices WHERE id=?',[$pending['invoice_id']])===$beforeInvoice && pv('SELECT config_snapshot FROM instances WHERE id=?',[$pending['instance_id']])===$beforeInstance && pv('SELECT recurring_price FROM instances WHERE id=?',[$pending['instance_id']])==='16.00','snapshot refresh does not reprice or rewrite invoice, instance configuration or frozen renewal');
check(snapshot($refresh,$rp,$pid)[0]===409,'replayed refresh with old version cannot write a second audit');
// A stale preview must not overwrite an intervening sale change.
$stale=['expected_version'=>$p['version'],'billing_cycles'=>['monthly'],'config_options'=>$p['defaults']];$sp=preview($stale,$pid);req('PATCH','/admin/products/'.$pid,edit($pid,['status'=>'hidden']),$admin);
check(snapshot($stale,$sp,$pid)[0]===409,'refresh preview cannot overwrite a newer edit');
$refresh['expected_version']=detail($pid)['version'];$rp=preview($refresh,$pid);$cacheBefore=pv('SELECT config_cache FROM products WHERE id=?',[$pid]);fixture('/__config_mode?mode=unavailable');
check(snapshot($refresh,$rp,$pid)[0]===409 && pv('SELECT config_cache FROM products WHERE id=?',[$pid])===$cacheBefore,'upstream outage saves no partial snapshot');
check(req('PATCH','/admin/products/'.$pid,edit($pid,['name'=>'Local edit during upstream outage']),$admin)[0]===200,'local name/state editing works during upstream quote outage');fixture('/__config_mode?mode=normal');
$db->prepare("UPDATE admins SET role='operator' WHERE id=?")->execute([$aid]);check(req('PATCH','/admin/products/'.$pid,edit($pid),$admin)[0]===403,'live role downgrade blocks product writes');$db->prepare("UPDATE admins SET role='super_admin' WHERE id=?")->execute([$aid]);
// Existing known remote resource still recovers while its product is hidden.
req('PATCH','/admin/products/'.$pid,edit($pid,['status'=>'active']),$admin);$p=detail($pid);$q=$catalog->calculate($pid,'monthly',$p['defaults']);$o=array_replace($order,['checkout_key'=>'admin-investigation-0002','config_options'=>$p['defaults'],'quote_token'=>$q['quote_token'],'use_balance'=>true]);$lost=$bill->checkout($uid,$o);
fixture('/__mode?mode=drop_after_create');$worker->runOnce('admin-hidden-lost-response');
check(pv('SELECT status FROM tasks WHERE invoice_id=?',[$lost['invoice_id']])==='investigating','lost response retains original paid task for investigation');
$creates=fixture('/__state')['creates'];req('PATCH','/admin/products/'.$pid,edit($pid,['status'=>'hidden','markup_type'=>'none','markup_value'=>'0.00']),$admin);
$db->prepare('UPDATE tasks SET next_retry_at=NULL WHERE invoice_id=?')->execute([$lost['invoice_id']]);$worker->runOnce('admin-hidden-investigation');
check(pv('SELECT status FROM tasks WHERE invoice_id=?',[$lost['invoice_id']])==='success' && fixture('/__state')['creates']===$creates,'delisting and repricing do not break original investigation or repurchase resource');
// Audit failure rolls back product edits; funds and tasks never participate in the edit transaction.
$before=detail($pid);$count=$before['audit']['total'];
$db->exec("CREATE TRIGGER product_audit_fault BEFORE INSERT ON admin_audit_logs FOR EACH ROW BEGIN IF NEW.action='product_update' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated product audit fault'; END IF; END");
try{check(req('PATCH','/admin/products/'.$pid,edit($pid,['name'=>'must roll back']),$admin)[0]===500,'audit persistence failure rejects the whole catalog edit');}finally{$db->exec('DROP TRIGGER product_audit_fault');}
check(detail($pid)['name']===$before['name'] && detail($pid)['audit']['total']===$count,'catalog state and audit remain atomic on failure');
check(req('GET','/admin/products/'.$pid.'?audit_page=999',null,$admin)[1]['data']['audit']['items']===[],'product audit supports bounded pagination');
check(req('GET','/admin/products/9999999',null,$admin)[0]===404,'missing product returns 404');
check(req('POST','/admin/products/preview',array_replace($input,['billing_cycles'=>[['monthly']]]),$admin)[0]===400,'nested billing cycles fail closed as validation');
// Fixed products use the same import path; concurrent response retries must converge.
$fixed=array_replace($input,['remote_pid'=>391,'name'=>'Concurrent fixed product']);$fp=preview($fixed);
$db->prepare('UPDATE providers SET status=0 WHERE id=?')->execute([$provider]);
try{check(snapshot($fixed,$fp)[0]===409 && (int)pv('SELECT COUNT(*) FROM products WHERE provider_id=? AND remote_pid=391',[$provider])===0,'disabled provider cannot be committed from an earlier preview');}finally{$db->prepare('UPDATE providers SET status=1 WHERE id=?')->execute([$provider]);}
$fp=preview($fixed);$responses=concurrent('POST','/admin/products/import',$fixed+['preview_token'=>$fp['preview_token'],'evidence'=>'OPS-CONCURRENT: same fixed product confirmation'],$admin);
$ids=array_map(fn($r)=>$r[1]['data']['product_id']??0,$responses);$fixedId=$ids[0];
check(array_column($responses,0)===[200,200,200,200] && $fixedId>0 && count(array_unique($ids))===1,'concurrent fixed product import retries return one product');
check((int)pv('SELECT COUNT(*) FROM products WHERE provider_id=? AND remote_pid=391',[$provider])===1 && detail($fixedId)['audit']['total']===1,'concurrent import persists one catalog row and one audit');
check(detail($fixedId)['fields']===[] && detail($fixedId)['prices']['monthly']==='0.01','empty-form upstream product remains a fixed snapshot with a reference price');
$responses=concurrent('PATCH','/admin/products/'.$fixedId,edit($fixedId,['status'=>'active']),$admin);$codes=array_column($responses,0);sort($codes);
check($codes===[200,409,409,409] && detail($fixedId)['audit']['total']===2,'concurrent stale edits accept exactly one change and one additional audit');
// Direct CLI stays compatible; new manual base pricing has no upstream dependency.
$manual=(new App\Services\DemoService())->seed();check(req('PATCH','/admin/products/'.$manual,edit($manual,['markup_type'=>'fixed','markup_value'=>'3.00']),$admin)[0]===200 && detail($manual)['prices']['monthly']==='13.00','manual product markup uses original local base once');
check(req('PATCH','/admin/products/'.$manual,edit($manual,['markup_value'=>'5.00']),$admin)[0]===200 && detail($manual)['prices']['monthly']==='15.00','manual repricing does not compound previous markup');
fixture('/__mode?mode=normal');fixture('/__config_mode?mode=normal');
echo "Admin product checks: {$checks}\n";
