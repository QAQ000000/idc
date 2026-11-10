<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use App\Core\{Database,Security,RedisStore,ReadObservationCache};
use App\Services\{AuthService,BillingService,OutboxWorker,ProductSnapshotService,InstancePowerService,InstanceDisplayService,ProviderService};
if(getenv('APP_ENV')!=='test'||getenv('DB_DATABASE')!=='jianyun_test'){throw new RuntimeException('Isolated test environment required');}
set_exception_handler(static function(Throwable $e):never{fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
// Separate processes exercise the real shared Redis lock, not in-process mocks.
if(($argv[1]??'')==='child'){
    $v=(new InstanceDisplayService())->power((int)$argv[2],(int)$argv[3],true);
    echo json_encode($v,JSON_THROW_ON_ERROR);exit($v['state']==='on'?0:1);
}
$db=Database::getInstance();$checks=0;
function check(bool $ok,string $label):void{global $checks;if(!$ok){throw new RuntimeException('FAIL display: '.$label);}$checks++;echo "PASS display: $label\n";}
function fixture(string $p):array{return json_decode(file_get_contents(getenv('UPSTREAM_TEST_URL').$p),true,64,JSON_THROW_ON_ERROR);}
function http(string $path,?string $token=null,?array $body=null):array{
 $c=curl_init(getenv('TEST_BASE_URL').'/api/v1'.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>45,CURLOPT_HTTPHEADER=>array_filter(['Content-Type: application/json',$token?'Authorization: Bearer '.$token:null])]);if($body!==null){curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($body)]);}$r=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);return [$status,json_decode($r,true,64,JSON_THROW_ON_ERROR)];
}
function readView(string $path):array{global $token;[$status,$r]=http($path,$token);check($status===200,'display request succeeds');return $r['data'];}
function counts():array{return fixture('/__request_counts');}
function delta(array $before,string $path):int{return (counts()[$path]??0)-($before[$path]??0);}
$db->exec("UPDATE tasks SET status='success',lease_until=NULL WHERE status IN ('pending','running','investigating')");
fixture('/__mode?mode=normal');fixture('/__power_mode?mode=normal');fixture('/__reinstall_mode?mode=normal');fixture('/__token_mode?mode=opaque');
$a=(new AuthService())->register(['email'=>'display@example.test','password'=>'DisplayTest!2026']);$uid=(int)$a['user']['id'];$token=Security::generateToken(['uid'=>$uid]);
$db->prepare('UPDATE users SET balance=100 WHERE id=?')->execute([$uid]);
$db->prepare('INSERT INTO providers(name,driver,endpoint,credentials) VALUES(?,?,?,?)')->execute(['display-fixture','zjmf',getenv('UPSTREAM_TEST_URL'),json_encode(['username'=>Security::encrypt('fixture'),'password'=>Security::encrypt('fixture-secret')])]);$provider=(int)$db->lastInsertId();
$product=(new ProductSnapshotService())->save(['provider_id'=>$provider,'remote_pid'=>391,'name'=>'Display fixture','type'=>'cloud','billing_cycles'=>['monthly']])['product_id'];
$order=(new BillingService())->checkout($uid,['product_id'=>$product,'hostname'=>'Display8','password'=>'DisplayRoot!2026','billing_cycle'=>'monthly','use_balance'=>true]);$iid=(int)$order['instance_id'];(new OutboxWorker())->runOnce('display-create');
$state="/instances/$iid/runtime?view=state";$observation="/instances/$iid/runtime?view=observation";$images="/instances/$iid/reinstall?view=images";
$c=counts();$r=readView($observation);check($r['state']==='unknown'&&$r['checked_at']===null&&counts()===$c,'empty observation does not fetch upstream');
fixture('/__token_mode?mode=exp');$c=counts();$r=readView($state);
check($r['state']==='on'&&delta($c,'/zjmf_api_login')===1&&delta($c,'/provision/default')===1&&delta($c,'/cart/hostinfo')===0&&delta($c,'/host/header')===0,'first latest read uses login plus status only');
check(!isset($r['actions'],$r['operation_token'])&&!str_contains(json_encode($r),'SECRET'),'observation has neither action authority nor provider response');
$c=counts();$r=readView($state);check(delta($c,'/zjmf_api_login')===0&&delta($c,'/provision/default')===1,'valid upstream token reused for a new live state');
$c=counts();$r=readView($observation);check($r['cached']&&$r['state']==='on'&&counts()===$c,'cached observation is immediate and does not read upstream');
$c=counts();$r=readView($images);check($r['state']==='available'&&count($r['images'])===2&&delta($c,'/host/header')===1&&!isset($r['available'],$r['operation_token']),'first image read projects safe options without authorization');
$c=counts();$r=readView($images);check($r['cached']&&counts()===$c,'reopening images uses safe cache');
check(http("/instances/$iid/reinstall",$token,['image_id'=>$r['images'][0]['id'],'operation_token'=>'cache-is-not-a-token','request_key'=>bin2hex(random_bytes(16)),'confirmed_system_disk_erase'=>true])[0]===409,'cached image cannot authorize reinstall');
$c=counts();$full=readView("/instances/$iid/reinstall");check($full['available']&&$full['operation_token']&&delta($c,'/host/header')===1,'operation preparation always rechecks current capability');
fixture('/__reinstall_mode?mode=schema');
check(http("/instances/$iid/reinstall",$token,['image_id'=>$full['images'][0]['id'],'operation_token'=>$full['operation_token'],'request_key'=>bin2hex(random_bytes(16)),'confirmed_system_disk_erase'=>true])[0]===409,'changed live image rules reject old confirmation');fixture('/__reinstall_mode?mode=normal');
$other=(new AuthService())->register(['email'=>'display-other@example.test','password'=>'DisplayOther!2026']);$ot=Security::generateToken(['uid'=>(int)$other['user']['id']]);
foreach([$state,$observation,$images] as $path){check(http($path)[0]===401&&http($path,$ot)[0]===404,'cache hit retains auth and owner isolation');}
check(http("/instances/$iid/runtime?view[]=state",$token)[0]===400&&http("/instances/$iid/reinstall?view[]=images",$token)[0]===400,'new modes reject array parameters');
$db->prepare('INSERT INTO tasks(instance_id,idempotency_key,action,payload) VALUES(?,?,?,?)')->execute([$iid,'display-block:'.$iid,'power','{}']);$tid=(int)$db->lastInsertId();
$c=counts();$r=readView($state);check($r['blocked']&&$r['state']==='unknown'&&counts()===$c,'pending gate rejects cached and latest state without upstream');
$db->prepare("UPDATE tasks SET status='success' WHERE id=?")->execute([$tid]);
$r=readView($observation);check($r['checked_at']===null,'task identity and terminal transition invalidate old observation');
readView($state);$db->prepare('UPDATE providers SET status=0 WHERE id=?')->execute([$provider]);$c=counts();
check(readView($observation)['state']==='unknown'&&readView($images)['state']==='unavailable'&&counts()===$c,'disabled provider cannot return cached observations');$db->prepare('UPDATE providers SET status=1 WHERE id=?')->execute([$provider]);
$due=$db->query('SELECT due_date FROM instances WHERE id='.$iid)->fetchColumn();$db->prepare("UPDATE instances SET due_date='2020-01-01 00:00:00' WHERE id=?")->execute([$iid]);$c=counts();
check(readView($state)['state']==='unknown'&&counts()===$c,'expired entitlement cannot use cached/live status');$db->prepare('UPDATE instances SET due_date=? WHERE id=?')->execute([$due,$iid]);
$db->prepare('UPDATE providers SET credentials=? WHERE id=?')->execute([json_encode(['username'=>Security::encrypt('fixture'),'password'=>Security::encrypt('fixture-secret')]),$provider]);$c=counts();
check(readView($observation)['checked_at']===null,'credential revision invalidates cached state');readView($state);check(delta($c,'/zjmf_api_login')===1,'credential revision isolates token cache');
// Revoke the actual cached token. Read-only rejection refreshes once and succeeds.
fixture('/__token_mode?mode=exp');$c=counts();check(readView($state)['state']==='on'&&delta($c,'/zjmf_api_login')===1&&delta($c,'/provision/default')===2,'rejected read token refreshes and retries once');
[$driver,$credentials]=(new ProviderService())->resolve($provider);$getToken=new ReflectionMethod($driver,'token');$request=new ReflectionMethod($driver,'request');$t=$getToken->invoke($driver,$credentials);
fixture('/__token_mode?mode=exp');$c=counts();$binding=$db->query('SELECT remote_instance_id FROM instances WHERE id='.$iid)->fetchColumn();
$r=$request->invoke($driver,$credentials,'/provision/default',['id'=>substr($binding,5),'func'=>'reboot','is_api'=>1],'POST',$t);
check(($r['status']??0)===405&&delta($c,'/provision/default')===1&&delta($c,'/zjmf_api_login')===0,'mutating authentication rejection is not replayed');
fixture('/__token_mode?mode=short');$c=counts();readView($state);readView($state);check(delta($c,'/zjmf_api_login')===2,'near-expiry token is not cached');fixture('/__token_mode?mode=exp');readView($state);
fixture('/__power_mode?mode=unavailable');check(readView($state)['state']==='unknown','failed latest query returns explicit unknown');fixture('/__power_mode?mode=normal');check(readView($observation)['state']==='on','failed latest query retains last successful observation');
// Real cross-process singleflight for the same resource.
fixture('/__display_delay?seconds=1');$c=counts();$children=[];
for($n=0;$n<2;$n++){$pipes=[];$p=proc_open([PHP_BINARY,__FILE__,'child',(string)$uid,(string)$iid],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$children[]=[$p,$pipes];}
foreach($children as [$p,$pipes]){$v=json_decode(stream_get_contents($pipes[1]),true);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($p)===0&&($v['state']??null)==='on','concurrent reader receives current shared result');}
check(delta($c,'/provision/default')===1,'same-resource concurrent live queries send only one upstream status');fixture('/__display_delay?seconds=0');
// Fencing: expired owners cannot overwrite a new publisher or delete its lock.
$cache=new ReadObservationCache();$redis=new RedisStore();$key=ReadObservationCache::key('fence-test');
$cache->remember($key,30,function()use($redis,$key):array{$redis->command('SET',$key.':lock','new-owner','EX','30');$redis->command('SET',$key,json_encode(['generation'=>'new','expires'=>time()+30,'value'=>['state'=>'new']]),'EX','30');return ['state'=>'old'];},true);
check($cache->read($key)['value']['state']==='new'&&$redis->command('GET',$key.':lock')==='new-owner','late publisher cannot replace newer value or unlock newer owner');$redis->command('DEL',$key,$key.':lock');
$called=0;try{$cache->remember($key,30,function()use(&$called):array{$called++;throw new RuntimeException('read failure');});}catch(RuntimeException){}
check($called===1&&$redis->command('GET',$key.':lock')===null,'failed loader is not repeated and releases only its own display lock');
// Token storage is encrypted, bounded and isolated even for otherwise identical account data.
fixture('/__token_mode?mode=exp');$fresh=$getToken->invoke($driver,$credentials,true);$tc=new \Plugins\providers\zjmf\TokenCache();$tokenKey=(new ReflectionMethod($tc,'key'))->invoke($tc,$credentials);
$raw=$redis->command('GET',$tokenKey);$ttl=$redis->command('TTL',$tokenKey);
check(is_string($raw)&&!str_contains($raw,$fresh)&&Security::decrypt($raw)!==null&&$ttl>0&&$ttl<=300,'Token cache stores authenticated ciphertext with at most five minute TTL');
$another=$credentials;$another['_connection_scope']=Security::requestFingerprint('another-connection');
check($tc->get($another)===null,'same account on a distinct connection cannot share cached Token');
$unscoped=$credentials;unset($unscoped['_connection_scope']);check($tc->get($unscoped)===null,'missing connection identity disables Token caching');
$redis->command('SET',$tokenKey,Security::encrypt(json_encode(['token'=>$fresh,'expires'=>time()-1])),'EX','30');check($tc->get($credentials)===null,'expired encrypted Token is rejected independently of Redis TTL');$redis->command('DEL',$tokenKey);
$redis->command('SET',$key,json_encode(['generation'=>'expired','expires'=>time()-1,'value'=>['state'=>'on']]),'EX','30');check($cache->read($key)===null,'expired display envelope is ignored independently of Redis TTL');$redis->command('DEL',$key);
$redis->command('SET',$key,'broken-json','EX','30');$called=0;$value=$cache->remember($key,30,function()use(&$called):array{$called++;return ['state'=>'on'];});check($called===1&&$value['state']==='on','corrupt cache envelope reloads exactly once');$redis->command('DEL',$key);
// Redis outage uses normal read/auth; it does not invent cached authority.
$original=\Illuminate\Support\Facades\Redis::getFacadeRoot();$bad=config('database.redis');$bad['default']['port']=1;$bad['default']['timeout']=0.1;
\Illuminate\Support\Facades\Redis::swap(new \Illuminate\Redis\RedisManager(app(),'phpredis',$bad));
try{
 $unavailable=false;try{$redis->command('PING');}catch(Throwable){$unavailable=true;}check($unavailable,'outage test really uses an unavailable isolated Redis endpoint');
 $called=0;$v=$cache->remember($key,30,function()use(&$called):array{$called++;return ['state'=>'on'];});check($called===1&&$v['state']==='on','Redis failure performs exactly one direct read');
 check((new \Plugins\providers\zjmf\TokenCache())->get($credentials)===null,'Redis failure never invents a login token');
}
finally{\Illuminate\Support\Facades\Redis::swap($original);}
fixture('/__token_mode?mode=opaque');
echo "Instance display checks: $checks\n";
