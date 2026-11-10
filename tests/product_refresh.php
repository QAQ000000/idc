<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
set_exception_handler(static function(Throwable $e):never{fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
use App\Core\{Database,Security};
use App\Services\{ProductSnapshotService,ProductRefreshService,AdminProductService,AdminAuthService,CatalogService,BillingService};
use Illuminate\Support\Facades\DB;
if(getenv('APP_ENV')!=='test'||getenv('DB_DATABASE')!=='jianyun_test'){throw new RuntimeException('Isolated DB required');}
$db=Database::getInstance();$checks=0;$url=getenv('UPSTREAM_TEST_URL');$refresh=new ProductRefreshService();
function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException('FAIL REFRESH: '.$label);$checks++;echo "PASS REFRESH: $label\n";}
function refreshValue(string $sql,array $args=[]):mixed{global $db;$s=$db->prepare($sql);$s->execute($args);return $s->fetchColumn();}
function row(int $id):array{global $db;$s=$db->prepare('SELECT p.*,r.driver FROM products p JOIN providers r ON r.id=p.provider_id WHERE p.id=?');$s->execute([$id]);return $s->fetch();}
function metadata(int $id):array{return json_decode(refreshValue('SELECT value FROM system_settings WHERE `key`=?',['product.refresh.'.$id])?:'{}',true);}
function due(int $id):void{global $db;$m=metadata($id);$m['last_attempt_at']=time()-601;$db->prepare("INSERT INTO system_settings(`key`,`group`,value) VALUES(?,'catalog',?) ON DUPLICATE KEY UPDATE value=VALUES(value)")->execute(['product.refresh.'.$id,json_encode($m)]);}
function mode(string $mode):void{global $url;file_get_contents($url.'/__config_mode?mode='.$mode);}
function counts():array{global $url;return json_decode(file_get_contents($url.'/__request_counts'),true);}
function rejected(callable $fn,string $label):void{try{$fn();}catch(RuntimeException){check(true,$label);return;}throw new LogicException('Expected failure '.$label);}
function batch(array $ids):array{config(['jianyun.product_refresh_ids'=>implode(',',$ids)]);return (new ProductRefreshService())->runBatch();}
function digest(array $tables):array{global $db;$out=[];foreach($tables as $table){$out[$table]=hash('sha256',json_encode($db->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll()));}return $out;}
function spawn(int $id):array{
    $code='require "bootstrap.php";config(["jianyun.product_refresh_ids"=>"'.$id.'"]);echo json_encode((new App\\Services\\ProductRefreshService)->runBatch(),JSON_THROW_ON_ERROR);';
    $pipes=[];$p=proc_open([PHP_BINARY,'-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));fclose($pipes[0]);return [$p,$pipes];
}
function finish(array $child):array{[$p,$pipes]=$child;$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);$exit=proc_close($p);if($exit!==0){throw new RuntimeException('Child refresh failed: '.$err);}return json_decode($out,true,64,JSON_THROW_ON_ERROR);}
function waitForRead(array $before):void{$deadline=microtime(true)+10;do{if((counts()['/cart/get_product_config']??0)>($before['/cart/get_product_config']??0)){return;}usleep(20000);}while(microtime(true)<$deadline);throw new RuntimeException('Child refresh did not start read');}
mode('normal');
$db->prepare('INSERT INTO providers(name,driver,endpoint,credentials) VALUES(?,?,?,?)')
    ->execute(['Refresh isolated supplier','zjmf',$url,json_encode(['username'=>Security::encrypt('fixture'),'password'=>Security::encrypt('fixture')])]);
$provider=(int)$db->lastInsertId();
$input=['provider_id'=>$provider,'remote_pid'=>389,'name'=>'Refresh rule fixture','type'=>'cloud','markup_type'=>'none','markup_value'=>'0.00','billing_cycles'=>['monthly']];
$snapshots=new ProductSnapshotService();$pid=$snapshots->save($input)['product_id'];$input['product_id']=$pid;
$aid=(new AdminAuthService())->create(['username'=>'refresh-admin','email'=>'refresh-admin@example.test','password'=>'RefreshAdmin!2026','role'=>'super_admin']);
$admin=new AdminProductService();$catalog=new CatalogService();
$old=row($pid);$cache=json_decode($old['config_cache'],true);$options=$cache['default_config'];
$q=$catalog->calculate($pid,'monthly',$options);$version=$admin->detail($pid)['version'];
$user=(new App\Services\AuthService())->register(['email'=>'refresh-customer@example.test','password'=>'RefreshCustomer!2026']);
$order=['product_id'=>$pid,'billing_cycle'=>'monthly','hostname'=>'refresh-order','password'=>'RefreshPassword12','config_options'=>$options,
    'checkout_key'=>'snapshot-refresh-original-order','quote_token'=>$q['quote_token'],'use_balance'=>false];
$invoice=(new BillingService())->checkout((int)$user['user']['id'],$order);
$moneyTables=['users','instances','invoices','transactions','payment_records','tasks','commissions'];$baseline=digest($moneyTables);
check(batch([])===['status'=>'disabled','items'=>[]],'empty allowlist disables all upstream work');
foreach(['0','1,1','1, 2','1,,2','1,','4294967296',str_repeat('1,',50).'2'] as $bad){config(['jianyun.product_refresh_ids'=>$bad]);rejected(fn()=>$refresh->runBatch(),'reject invalid scope '.$bad);}
$r=batch([$pid]);check($r['items']===[['product_id'=>$pid,'result'=>'unchanged']],'same complete snapshot only updates observation');
check(row($pid)['config_cache']===$old['config_cache'] && $admin->detail($pid)['version']===$version,'unchanged snapshot preserves exact cache bytes and admin version');
$m=metadata($pid);check($m['result']==='unchanged' && $m['valid_until']-$m['last_success_at']===900,'freshness is separate system setting with15 minute validity');
check($admin->detail($pid)['snapshot_refresh']['fresh'] && $admin->detail($pid)['snapshot_refresh']['pricing_mode']==='online','admin sees fresh observation without local pricing enabled');
$pre=$catalog->preflight($pid,'monthly',$options);$current=$catalog->quote($db,$pid,'monthly',$options,false,$pre);$catalog->assertConfirmed($current,$pre,$q['quote_token']);check(true,'quote confirmation survives same-content publication');
$before=counts();check(batch([$pid])['items']===[] && counts()===$before,'successful refresh obeys ten-minute interval without repeated reads');
mode('refresh_stock');due($pid);$r=batch([$pid]);check($r['items'][0]['result']==='unchanged' && row($pid)['config_cache']===$old['config_cache'],'inventory-only change does not change sale content or quote version');mode('normal');
$m=metadata($pid);$m['valid_until']=time()-1;$db->prepare('UPDATE system_settings SET value=? WHERE `key`=?')->execute([json_encode($m),'product.refresh.'.$pid]);
check(!$refresh->observation($pid,$cache)['fresh'],'expired observation is not local price authority');
$before=counts();$catalog->calculate($pid,'monthly',$options);check(counts()['/cart/get_total']>$before['/cart/get_total'],'expired snapshot still requires successful online quotation');
foreach(['refresh_bad','refresh_unavailable','pricing_mismatch','refresh_drift'] as $fault){
    mode($fault);due($pid);$saved=row($pid)['config_cache'];$r=batch([$pid]);
    check($r['items'][0]['result']==='failed' && row($pid)['config_cache']===$saved,'failure retains entire previous snapshot: '.$fault);
    check(metadata($pid)['reason']==='RULES_UPSTREAM_OR_VERSION_REJECTED' && metadata($pid)['last_success_at']===$m['last_success_at'],'failure preserves last success with sanitized status');
}
mode('refresh_unavailable');rejected(fn()=>$catalog->calculate($pid,'monthly',$options),'online failure does not offer stale snapshot as a tradable quote');mode('normal');
// A change in a currently unused choice must still publish new rules and invalidate old confirmations.
due($pid);mode('refresh_unused_price');$r=batch([$pid]);$changed=row($pid);$newCache=json_decode($changed['config_cache'],true);
check($r['items'][0]['result']==='published' && $newCache['snapshot_revision']===$cache['snapshot_revision']+1,'unused choice price rule change publishes complete new revision');
check($newCache['prices']===$cache['prices'] && $changed['config_cache']!==$old['config_cache'],'same default price does not hide nondefault price rule changes');
$pre=$catalog->preflight($pid,'monthly',$options);$current=$catalog->quote($db,$pid,'monthly',$options,false,$pre);
rejected(fn()=>$catalog->assertConfirmed($current,$pre,$q['quote_token']),'old quote rejects after unused price rule changes');
mode('normal');due($pid);batch([$pid]);$restored=json_decode(row($pid)['config_cache'],true);
check($restored['snapshot_revision']===$cache['snapshot_revision']+2,'returning to previous content advances revision and prevents ABA');
$pre=$catalog->preflight($pid,'monthly',$options);$current=$catalog->quote($db,$pid,'monthly',$options,false,$pre);rejected(fn()=>$catalog->assertConfirmed($current,$pre,$q['quote_token']),'content rollback cannot revive old quote');
mode('refresh_unavailable');$replay=$order;$replay['quote_token']='expired';
check((new BillingService())->checkout((int)$user['user']['id'],$replay)['invoice_id']===$invoice['invoice_id'],'original request key restores historical invoice through refresh and supplier outage');mode('normal');
// Explicit MySQL mutex overlap and automatic release when a process dies mid-network-read.
$cfg=config('database.connections.mysql');$other=new PDO('mysql:host='.$cfg['host'].';port='.$cfg['port'].';dbname='.$cfg['database'],$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$lock='jy-refresh:'.substr(hash('sha256',$cfg['database']),0,40);$s=$other->prepare('SELECT GET_LOCK(?,0)');$s->execute([$lock]);
check(batch([$pid])['status']==='overlap_skipped','second DB session cannot overlap the refresh batch');$s=$other->prepare('SELECT RELEASE_LOCK(?)');$s->execute([$lock]);
due($pid);mode('refresh_delay');$saved=row($pid)['config_cache'];$before=counts();$child=spawn($pid);waitForRead($before);
check(batch([$pid])['status']==='overlap_skipped','actual running refresh excludes another runner');
proc_terminate($child[0],9);foreach([1,2] as $i){stream_get_contents($child[1][$i]);fclose($child[1][$i]);}proc_close($child[0]);
check(row($pid)['config_cache']===$saved && metadata($pid)['result']==='running','killed refresh leaves prior snapshot and persistent unfinished attempt');
mode('normal');due($pid);check(batch([$pid])['items'][0]['result']==='unchanged','next allowed attempt recovers after crash without resetting sale facts');
// Concurrent admin changes during upstream reads must win; no stale-name/markup/config overwrite.
due($pid);mode('refresh_delay');$before=counts();$child=spawn($pid);waitForRead($before);$detail=$admin->detail($pid);
$admin->update($aid,$pid,['name'=>'Concurrent operator name','status'=>'active','markup_type'=>'fixed','markup_value'=>'2.00','expected_version'=>$detail['version'],'evidence'=>'Concurrent refresh acceptance: deliberate audited markup edit']);
$edited=row($pid);$r=finish($child);check($r['items'][0]['result']==='failed' && row($pid)['config_cache']===$edited['config_cache'] && row($pid)['name']==='Concurrent operator name','admin markup/name change during fetch rejects stale publication');mode('normal');
// Failed observation write must roll back the corresponding changed product JSON as one transaction.
$db->exec("CREATE TRIGGER refresh_publication_fail BEFORE UPDATE ON system_settings FOR EACH ROW BEGIN IF NEW.`key`='product.refresh.$pid' AND JSON_UNQUOTE(JSON_EXTRACT(NEW.value,'$.result'))='published' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Isolated publication failure'; END IF; END");
try{
    due($pid);mode('refresh_rule_price');$saved=row($pid)['config_cache'];$r=batch([$pid]);
    check($r['items'][0]['result']==='failed' && row($pid)['config_cache']===$saved && metadata($pid)['result']==='failed','failure committing observation rolls back full changed snapshot atomically');
}finally{$db->exec('DROP TRIGGER refresh_publication_fail');mode('normal');}
// Refresh changed default price, then compare local and online from published complete rules.
due($pid);mode('refresh_rule_price');$r=batch([$pid]);$p=row($pid);$new=json_decode($p['config_cache'],true);
check($r['items'][0]['result']==='published' && $new['prices']['monthly']==='260.00' && $new['reference_costs']['monthly']['first']==='258.00','complete content publishes config, upstream costs and current local markup together');
[$driver,$credentials]=(new App\Services\ProviderService())->resolve($provider);$online=$driver->preflightPrice(389,'monthly',$options,$credentials);$driver->validateSnapshotRules($new['schema'],$options,['monthly'=>['first'=>$online->amount,'recurring'=>$online->recurringAmount]]);check(true,'published rules still match online first and renewal amounts');mode('normal');
// Unsupported complete matrices never use partial/default guesses; existing online sales remain available.
$unsupported=$snapshots->save(array_replace($input,['product_id'=>null,'remote_pid'=>14,'name'=>'Refresh incomplete matrix']))['product_id'];
$saved=row($unsupported)['config_cache'];$r=batch([$unsupported]);check($r['items'][0]['result']==='failed' && row($unsupported)['config_cache']===$saved,'unsupported full-rule snapshot keeps old snapshot');
$opts=json_decode($saved,true)['default_config'];check($catalog->calculate($unsupported,'monthly',$opts)['total']==='16.00','unsupported scheduled rules still use normal online pricing');
// Bounds, fairness, retired products and allowlist isolation.
$ids=[];for($i=0;$i<3;$i++){$ids[]=$snapshots->save(array_replace($input,['product_id'=>null,'name'=>'Batch fixture'.$i]))['product_id'];}
$r=batch($ids);check(count($r['items'])===2,'one invocation processes at most2 products');$r2=batch($ids);check(count($r2['items'])===1,'unprocessed due product selected before recently attempted items');
$excluded=$ids[0];due($excluded);$before=counts();batch([$ids[1]]);check(metadata($excluded)['last_attempt_at']<time()-600 && counts()===$before,'non-allowlisted products never refreshed');
$db->prepare("UPDATE products SET status='retired' WHERE id=?")->execute([$excluded]);check(batch([$excluded])['items']===[],'retired product is not scheduled');
check(digest($moneyTables)===$baseline,'all refreshes preserve customers, invoices, receipts, wallet, instances, tasks and commissions');
config(['jianyun.product_refresh_ids'=>'']);
echo "Product refresh checks: $checks\n";
