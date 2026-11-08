<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
set_exception_handler(static function(Throwable $e):never{fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
use App\Core\{Database,Security,ApiException,RedisStore};
use App\Services\{InstanceConsoleService,ProviderService,InstancePowerService};
use App\ConsoleRelay\{RfbBridge,TargetPolicy};
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
[$driver,$credentials]=(new ProviderService())->resolve((int)$i['provider_id']);$target=$driver->openConsole(InstancePowerService::context($i),$credentials);
consoleCheck(str_starts_with($target->url,'ws://127.0.0.1:') && $target->password==='password','driver validates original resource and extracts server-only target');
foreach(['https://127.0.0.1:1/','ws://user:pass@127.0.0.1:1/','wss://unapproved.example/','wss://approved.example/#secret',"wss://approved.example/\r\n"] as $url){consoleReject(fn()=>TargetPolicy::validate($url),'unsafe or unapproved target rejected');}
$old=config('console.targets');config(['console.targets'=>['127.0.0.1:443'],'console.test_loopback'=>false]);consoleReject(fn()=>TargetPolicy::resolve('wss://127.0.0.1/'),'private IP blocked without isolated-test bypass');config(['console.targets'=>$old,'console.test_loopback'=>true]);
$redis=new RedisStore();$rate='console:rate:'.$user.':'.$id;
$issue=function()use($s,$token,$id,$redis,$rate){$redis->command('DEL',$rate);return $s->issue($token,$id);};
$a=$issue();consoleCheck(array_keys($a)===['path','expires_in','max_duration']&&!str_contains(json_encode($a),'password'),'API returns only same-site opaque authorization');
consoleReject(fn()=>$s->issue($token,$id),'issuance rate limited');
$ticket=basename($a['path']);$raw=$redis->command('GET','console:ticket:'.hash('sha256',$ticket));consoleCheck(!str_contains($raw,'password')&&!str_contains($raw,'127.0.0.1'),'Redis target and credential encrypted');
consoleReject(fn()=>$s->consume($token,$ticket,'https://evil.example'),'cross-site WebSocket rejected');
$r=$s->consume($token,$ticket,'https://console.example.test');consoleCheck($r['instance']===$id,'owner plus exact Origin and ticket authorized');
consoleReject(fn()=>$s->consume($token,$ticket,'https://console.example.test'),'ticket replay rejected');
$a=$issue();consoleReject(fn()=>$s->consume(Security::generateToken(['uid'=>$user],3600),basename($a['path']),'https://console.example.test'),'ticket bound to original main session');
$a=$issue();$key='console:ticket:'.hash('sha256',basename($a['path']));$r=json_decode(Security::decrypt($redis->command('GET',$key)),true);$r['expires']=time()-1;$redis->command('SET',$key,Security::encrypt(json_encode($r)),'EX','60');consoleReject(fn()=>$s->consume($token,basename($a['path']),'https://console.example.test'),'expired console authorization rejected');
$a=$issue();$db->prepare('UPDATE instances SET due_date=DATE_ADD(due_date,INTERVAL 1 DAY) WHERE id=?')->execute([$id]);consoleReject(fn()=>$s->consume($token,basename($a['path']),'https://console.example.test'),'changed binding rejected at handshake');$db->prepare('UPDATE instances SET due_date=? WHERE id=?')->execute([$i['due_date'],$id]);
consoleCheck(bin2hex(RfbBridge::challenge(hex2bin('000102030405060708090a0b0c0d0e0f'),'password'))==='b866924125c8eebb9debc1db61c538e2','server DES challenge matches independent noVNC vector');
$up='';$down='';$b=new RfbBridge('password',function($v)use(&$up){$up.=$v;},function($v)use(&$down){$down.=$v;});
foreach(str_split("RFB 003.008\n\x01\x02".hex2bin('000102030405060708090a0b0c0d0e0f')."\0\0\0\0") as $byte){$b->upstream($byte);}
consoleCheck($up==="RFB 003.008\n\x02".hex2bin('b866924125c8eebb9debc1db61c538e2')&&$down==="RFB 003.008\n",'fragmented upstream authentication completed without forwarding password/challenge');
$b->client("RFB 003.008\n\x01\x01");$head=pack('nn',2,2).str_repeat("\0",16);$name='supplier.example SECRET';$b->upstream($head.pack('N',strlen($name)).$name.'pixels');consoleCheck($b->ready()&&str_ends_with($down,'JianYun Consolepixels')&&!str_contains($down,'supplier.example'),'upstream desktop title removed and initial framebuffer preserved');
$b->client('keys');$b->upstream('frame');consoleCheck(str_ends_with($up,'keys')&&str_ends_with($down,'frame'),'authenticated RFB traffic relayed both ways');
consoleReject(fn()=>(new RfbBridge('',fn()=>null,fn()=>null))->upstream("RFB 003.003\n"),'unsupported RFB version fails closed');
consoleReject(fn()=>(new RfbBridge('',fn()=>null,fn()=>null))->upstream("RFB 003.008\n\x01\x13"),'unsupported upstream authentication fails closed');
consoleReject(fn()=>(new RfbBridge('',fn()=>null,fn()=>null))->client('early'),'early client data rejected');
$redis->command('DEL',$rate);
file_put_contents(getenv('CONSOLE_TEST_AUTH_FILE'),json_encode(['token'=>$token,'instance'=>$id,'other'=>Security::generateToken(['uid'=>$other]),'short'=>Security::generateToken(['uid'=>$user],8)]));chmod(getenv('CONSOLE_TEST_AUTH_FILE'),0600);
echo "Console checks: $checks\n";
