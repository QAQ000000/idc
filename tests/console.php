<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
set_exception_handler(static function(Throwable $e):never{fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
use App\Core\{Database,Security,ApiException,RedisStore};
use App\Services\{InstanceConsoleService,ProviderService,InstancePowerService};
use App\Services\ConsoleTargetPolicy;
if(getenv('APP_ENV')!=='test'||getenv('DB_DATABASE')!=='jianyun_test'){throw new RuntimeException('Isolated environment required');}
$checks=0;$db=Database::getInstance();
function consoleCheck(bool $v,string $m):void{global $checks;if(!$v){throw new RuntimeException('FAIL console: '.$m);}$checks++;echo "PASS console: $m\n";}
function consoleReject(callable $f,string $m):void{try{$f();}catch(Throwable){consoleCheck(true,$m);return;}throw new RuntimeException('Expected console rejection: '.$m);}
$i=$db->query("SELECT * FROM instances WHERE name='网页电源验收实例'")->fetch();$id=(int)$i['id'];$user=(int)$i['user_id'];$s=new InstanceConsoleService();$token=Security::generateToken(['uid'=>$user]);
$other=(int)$db->query("SELECT id FROM users WHERE email='power-other@example.test'")->fetchColumn();
consoleReject(fn()=>$s->principal('bad'),'invalid main login rejected');
consoleReject(fn()=>$s->principal(Security::generateToken(['uid'=>$user,'sub_type'=>'admin'])),'admin scope rejected');
consoleReject(fn()=>$s->principal(Security::generateToken(['uid'=>$user,'is_impersonated'=>true])),'impersonation rejected');
consoleReject(fn()=>$s->principal(Security::generateToken(['uid'=>$user],-1)),'expired main login rejected');
consoleReject(fn()=>$s->eligible($other,$id),'cross-owner access rejected');
$db->prepare("UPDATE users SET status='suspended' WHERE id=?")->execute([$user]);consoleReject(fn()=>$s->principal($token),'suspended owner rejected');$db->prepare("UPDATE users SET status='active' WHERE id=?")->execute([$user]);
foreach(['suspended','pending','terminated'] as $status){$db->prepare('UPDATE instances SET status=? WHERE id=?')->execute([$status,$id]);consoleReject(fn()=>$s->eligible($user,$id),'non-active instance rejected: '.$status);}
$db->prepare("UPDATE instances SET status='active',due_date=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE id=?")->execute([$id]);consoleReject(fn()=>$s->eligible($user,$id),'expired instance rejected');$db->prepare('UPDATE instances SET due_date=? WHERE id=?')->execute([$i['due_date'],$id]);
$db->prepare("INSERT INTO tasks(instance_id,idempotency_key,action,payload) VALUES(?,'console-block','power','{}')")->execute([$id]);$tid=(int)$db->lastInsertId();consoleReject(fn()=>$s->eligible($user,$id),'queued power blocks console');$db->prepare("UPDATE tasks SET status='failed',last_error='MANUAL_AUDIT_REQUIRED:TEST' WHERE id=?")->execute([$tid]);consoleReject(fn()=>$s->eligible($user,$id),'unknown task keeps console gate');$db->prepare("UPDATE tasks SET status='success',last_error=NULL WHERE id=?")->execute([$tid]);
// Financial API and cloud console use distinct hostnames, even in the loopback fixture.
$endpoint=getenv('UPSTREAM_TEST_URL');$consoleEndpoint=str_replace('127.0.0.1','localhost',$endpoint);
$db->prepare('UPDATE providers SET endpoint=? WHERE id=?')->execute([$consoleEndpoint,$i['provider_id']]);
[$driver,$credentials]=(new ProviderService())->resolve((int)$i['provider_id']);$target=$driver->openConsole(InstancePowerService::context($i),$credentials);
consoleCheck(str_starts_with($target->url,'ws://127.0.0.1:') && $target->password==='password','driver extracts only cloud connection credentials');
foreach(['https://127.0.0.1:1/','ws://user:pass@127.0.0.1:1/','wss://unapproved.example/','wss://approved.example/#secret',"wss://approved.example/\r\n"] as $url){consoleReject(fn()=>ConsoleTargetPolicy::validate($url),'unsafe or unapproved target rejected');}
$old=config('console.targets');config(['console.targets'=>['127.0.0.1:443'],'console.test_loopback'=>false]);consoleReject(fn()=>ConsoleTargetPolicy::validate('wss://127.0.0.1/'),'private target blocked without isolated-test bypass');config(['console.targets'=>$old,'console.test_loopback'=>true]);
$redis=new RedisStore();$rate='console:rate:'.$user.':'.$id;
$issue=function()use($s,$token,$id,$redis,$rate){$redis->command('DEL',$rate);return $s->issue($token,$id);};
$before=[];foreach(['users','instances','invoices','transactions','tasks','payment_records'] as $table){$before[$table]=$db->query("SELECT * FROM $table ORDER BY id")->fetchAll();}
$a=$issue();consoleCheck(array_keys($a)===['websocket_url','password','binding','client_disconnect_at'] && $a['password']==='password','API projects cloud URL and connection password only');
consoleCheck(!str_contains(json_encode($a),'SECRET-HOST-TOKEN')&&!str_contains(json_encode($a),'localhost')&&!str_contains(json_encode($a),'fixture-secret')&&!str_contains(json_encode($a),'PowerRoot!'),'financial host, account credentials and host password/token excluded');
consoleCheck($a['client_disconnect_at']<=time()+900 && $a['client_disconnect_at']>time(),'client disconnect deadline bounded by 15 minutes');
consoleCheck($redis->command('KEYS','console:ticket:*')===[],'no relay tickets or connection secrets stored in Redis');
consoleReject(fn()=>$s->issue($token,$id),'authorization rate limited');
consoleCheck($s->check($token,$id)===['binding'=>$a['binding']],'continuity check exposes no credentials and preserves frozen binding');
$db->prepare('UPDATE instances SET due_date=DATE_ADD(due_date,INTERVAL 1 DAY) WHERE id=?')->execute([$id]);consoleCheck($s->check($token,$id)['binding']!==$a['binding'],'binding change is visible to the open page');$db->prepare('UPDATE instances SET due_date=? WHERE id=?')->execute([$i['due_date'],$id]);
$db->prepare("UPDATE instances SET status='suspended' WHERE id=?")->execute([$id]);consoleReject(fn()=>$s->check($token,$id),'live page check rejects lost entitlement');$db->prepare("UPDATE instances SET status='active' WHERE id=?")->execute([$id]);
$db->prepare('UPDATE providers SET endpoint=? WHERE id=?')->execute([$endpoint,$i['provider_id']]);consoleReject($issue,'financial and console same hostname cannot be exposed as direct mode');$db->prepare('UPDATE providers SET endpoint=? WHERE id=?')->execute([$consoleEndpoint,$i['provider_id']]);
$dotted=str_replace('127.0.0.1','127.0.0.1.',$endpoint);$db->prepare('UPDATE providers SET endpoint=? WHERE id=?')->execute([$dotted,$i['provider_id']]);consoleReject($issue,'trailing DNS dot cannot disguise the financial hostname');$db->prepare('UPDATE providers SET endpoint=? WHERE id=?')->execute([$consoleEndpoint,$i['provider_id']]);
config(['console.targets'=>[]]);consoleReject($issue,'unconfigured target whitelist fails closed');config(['console.targets'=>$old]);
$admin=Security::generateToken(['uid'=>$user,'sub_type'=>'admin']);$otherToken=Security::generateToken(['uid'=>$other]);
function consoleHttp(string $path, ?string $token=null):array{
 $c=curl_init(getenv('TEST_BASE_URL').'/api/v1'.$path);curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>'{}',CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_HTTPHEADER=>array_filter(['Content-Type: application/json',$token?'Authorization: Bearer '.$token:null])]);$raw=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);return [$status,json_decode($raw,true,64,JSON_THROW_ON_ERROR)];
}
foreach(['','/check'] as $suffix){$path="/instances/$id/console".$suffix;consoleCheck(consoleHttp($path)[0]===401,'HTTP main login required for '.$suffix);consoleCheck(consoleHttp($path,$admin)[0]===401,'HTTP admin scope rejected for '.$suffix);consoleCheck(consoleHttp($path,$otherToken)[0]===404,'HTTP cross-owner rejected for '.$suffix);}
$redis->command('DEL',$rate);[$status,$response]=consoleHttp("/instances/$id/console",$token);consoleCheck($status===200 && isset($response['data']['websocket_url']) && !isset($response['data']['path']),'HTTP direct contract replaces relay ticket contract');
consoleCheck(consoleHttp("/instances/$id/console/check",$token)[1]['data']['binding']===$a['binding'],'HTTP continuity check matches original binding');
foreach($before as $table=>$rows){consoleCheck($db->query("SELECT * FROM $table ORDER BY id")->fetchAll()===$rows,'console authorization does not mutate '.$table);}
$redis->command('DEL',$rate);
echo "Console checks: $checks\n";
