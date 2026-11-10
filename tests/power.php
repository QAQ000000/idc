<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
set_exception_handler(static function(Throwable $e):never{fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
use App\Core\{Database,Security,ApiException};
use App\Services\{AuthService,AdminAuthService,BillingService,OutboxWorker,ProductSnapshotService,InstancePowerService,TaskResolutionService,RenewalService};
if(getenv('APP_ENV')!=='test'||getenv('DB_DATABASE')!=='jianyun_test'){throw new RuntimeException('Isolated test environment required');}
$db=Database::getInstance();$checks=0;
function check(bool $v,string $m):void{global $checks;if(!$v){throw new RuntimeException('FAIL power: '.$m);}$checks++;echo "PASS power: $m\n";}
function powerValue(string $sql,array $p=[]):mixed{global $db;$s=$db->prepare($sql);$s->execute($p);return $s->fetchColumn();}
function fixture(string $path):array{return json_decode(file_get_contents(getenv('UPSTREAM_TEST_URL').$path),true,64,JSON_THROW_ON_ERROR);}
function http(string $method,string $path,?array $body=null,?string $token=null):array{
 $c=curl_init(getenv('TEST_BASE_URL').'/api/v1'.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_TIMEOUT=>25,CURLOPT_HTTPHEADER=>array_filter(['Content-Type: application/json',$token?'Authorization: Bearer '.$token:null])]);if($body!==null){curl_setopt($c,CURLOPT_POSTFIELDS,json_encode($body));}$raw=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);return [$status,json_decode($raw,true,64,JSON_THROW_ON_ERROR)];
}
function runtime():array{global $iid,$token;[$s,$r]=http('GET',"/instances/$iid/runtime",null,$token);if($s!==200){throw new RuntimeException('Runtime failed');}return $r['data'];}
function command(string $action,?array $r=null,?string $key=null):array{global $iid,$token;return http('POST',"/instances/$iid/power",['action'=>$action,'operation_token'=>($r??runtime())['operation_token'],'request_key'=>$key??bin2hex(random_bytes(16))],$token);}
function task(int $id):array{global $db;$s=$db->prepare('SELECT * FROM tasks WHERE id=?');$s->execute([$id]);return $s->fetch();}
function reject(callable $f,string $m):void{try{$f();}catch(ApiException $e){check($e->httpStatus===409,$m);return;}throw new RuntimeException('Expected rejection: '.$m);}
function signed(array $claims):string{$b=rtrim(strtr(base64_encode(json_encode($claims)),'+/','-_'),'=');return $b.'.'.Security::requestFingerprint('power:'.$b);}
function claims(array $r):array{return json_decode(base64_decode(strtr(explode('.',$r['operation_token'])[0],'-_','+/')),true);}
$db->exec("UPDATE tasks SET status='success',lease_until=NULL WHERE status IN ('pending','running','investigating')");
fixture('/__mode?mode=normal');fixture('/__power_mode?mode=normal');
// Simulate the previous enum in this disposable DB, then execute the actual forward migration twice.
$db->exec("ALTER TABLE tasks MODIFY action ENUM('create','renew','upgrade','suspend','unsuspend','terminate','snapshot_create','snapshot_delete','backup_create','backup_delete') NOT NULL");
$count=powerValue('SELECT COUNT(*) FROM tasks');$migration=require dirname(__DIR__).'/database/migrations/2026_11_08_000005_power_operations.php';$migration->up();$migration->up();
check((int)powerValue('SELECT COUNT(*) FROM tasks')===(int)$count,'forward migration preserves prior tasks and is rerunnable');
$auth=(new AuthService())->register(['email'=>'power@example.test','password'=>'PowerTest!2026']);$uid=(int)$auth['user']['id'];$token=Security::generateToken(['uid'=>$uid]);
$db->prepare('UPDATE users SET balance=100 WHERE id=?')->execute([$uid]);
$db->prepare('INSERT INTO providers(name,driver,endpoint,credentials) VALUES(?,?,?,?)')->execute(['power-fixture','zjmf',getenv('UPSTREAM_TEST_URL'),json_encode(['username'=>Security::encrypt('fixture'),'password'=>Security::encrypt('fixture-secret')])]);$provider=(int)$db->lastInsertId();
$product=(new ProductSnapshotService())->save(['provider_id'=>$provider,'remote_pid'=>391,'name'=>'Power fixture','type'=>'cloud','billing_cycles'=>['monthly']])['product_id'];
$order=(new BillingService())->checkout($uid,['product_id'=>$product,'hostname'=>'PowerTest8','password'=>'PowerRoot!2026','billing_cycle'=>'monthly','use_balance'=>true]);
$iid=(int)$order['instance_id'];$worker=new OutboxWorker();$worker->runOnce('power-create');
$remote=powerValue('SELECT remote_instance_id FROM instances WHERE id=?',[$iid]);$rid=substr($remote,5);
check(powerValue('SELECT status FROM instances WHERE id=?',[$iid])==='active','fixture resource delivered through the real Worker');
$admin=(new AdminAuthService())->create(['username'=>'power-admin','email'=>'power-admin@example.test','password'=>'PowerAdmin!2026','role'=>'super_admin']);
$aid=is_array($admin)?(int)$admin['id']:(int)$admin;$at=Security::generateToken(['uid'=>$aid,'sub_type'=>'admin']);
$other=Security::generateToken(['uid'=>999999]);
check(http('GET',"/instances/$iid/runtime")[0]===401,'unauthenticated runtime is rejected');
check(http('GET',"/instances/$iid/runtime",null,$at)[0]===401,'admin token cannot enter owner routes');
check(http('GET',"/instances/$iid/runtime",null,$other)[0]===401,'nonexistent account token is rejected');
$auth2=(new AuthService())->register(['email'=>'power-other@example.test','password'=>'OtherPower!2026']);$ot=Security::generateToken(['uid'=>(int)$auth2['user']['id']]);
check(http('GET',"/instances/$iid/runtime",null,$ot)[0]===404,'another real customer cannot query resource state');
$localPath="/instances/$iid/runtime?view=tasks";
check(http('GET',$localPath)[0]===401&&http('GET',$localPath,null,$at)[0]===401&&http('GET',$localPath,null,$ot)[0]===404,'local power gate preserves authentication, customer scope and ownership');
check(http('GET',"/instances/$iid/runtime?view=invalid",null,$token)[0]===400&&http('GET',"/instances/$iid/runtime?view[]=tasks",null,$token)[0]===400,'runtime rejects invalid or array view modes');
$counts=fixture('/__request_counts');
[$localStatus,$localView]=http('GET',$localPath,null,$token);
check($localStatus===200&&$localView['data']===['blocked'=>false,'tasks'=>[]]&&fixture('/__request_counts')===$counts,'local power gate performs no upstream login or capability reads and issues no action token');
$r=runtime();check($r['state']==='on'&&$r['actions']===['stop','reboot'],'actual state and declared capabilities determine allowed controls');
$public=json_encode($r);check(!str_contains($public,'SECRET')&&!str_contains($public,'fixture-secret')&&!str_contains($public,'endpoint')&&!str_contains($public,'password'),'runtime projects only safe fields');
check(command('start',$r)[0]===409&&command('hard_off',$r)[0]===400,'redundant start and unsupported hard power are rejected');
$bad=$r;$bad['operation_token'].='x';check(command('reboot',$bad)[0]===409,'tampered operation confirmation is rejected');
$c=claims($r);$c['expires']=time()-1;$bad['operation_token']=signed($c);check(command('reboot',$bad)[0]===409,'expired confirmation is rejected');
$c=claims($r);$c['instance']++;$bad['operation_token']=signed($c);check(command('reboot',$bad)[0]===409,'confirmation cannot be reused for a different instance');
check(http('POST',"/instances/$iid/power",['action'=>'reboot','operation_token'=>$r['operation_token'],'request_key'=>str_repeat('a',20)],$ot)[0]===409,'owner-bound confirmation rejects another customer');
$imp=Security::generateToken(['uid'=>$uid,'is_impersonated'=>true,'operator_admin_id'=>$aid]);
check(http('POST',"/instances/$iid/power",['action'=>'reboot','operation_token'=>$r['operation_token'],'request_key'=>str_repeat('a',20)],$imp)[0]===403,'unimplemented impersonation workflow cannot create unaudited customer operations');
$before=[powerValue('SELECT balance FROM users WHERE id=?',[$uid]),powerValue('SELECT price_snapshot FROM invoices WHERE id=?',[$order['invoice_id']]),powerValue('SELECT config_snapshot FROM instances WHERE id=?',[$iid]),powerValue('SELECT due_date FROM instances WHERE id=?',[$iid])];
// Four independent requests with the same view confirmation and request key.
$multi=curl_multi_init();$handles=[];$body=json_encode(['action'=>'stop','operation_token'=>$r['operation_token'],'request_key'=>str_repeat('b',20)]);
for($n=0;$n<4;$n++){$c=curl_init(getenv('TEST_BASE_URL')."/api/v1/instances/$iid/power");curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$token]]);curl_multi_add_handle($multi,$c);$handles[]=$c;}
do{curl_multi_exec($multi,$active);if($active){curl_multi_select($multi,1);}}while($active);
$statuses=[];$tid=0;foreach($handles as $c){$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);$statuses[]=$status;if($status===200){$tid=json_decode(curl_multi_getcontent($c),true)['data']['task_id'];}curl_multi_remove_handle($multi,$c);curl_close($c);}curl_multi_close($multi);sort($statuses);
check($statuses===[200,409,409,409],'four concurrent repeated clicks insert one task and reject the other three');
check((int)fixture('/__state')['power_calls']===0,'HTTP submit never executes a mutating upstream command');
check(task($tid)['invoice_id']===null&&task($tid)['action']==='power','nonfinancial power task has no invented invoice');
check(runtime()['blocked']&&runtime()['actions']===[],'queued task immediately blocks further controls');
$counts=fixture('/__request_counts');$local=http('GET',$localPath,null,$token)[1]['data'];
check($local['blocked']&&$local['tasks'][0]['id']===$tid&&$local['tasks'][0]['result']==='pending'&&fixture('/__request_counts')===$counts,'queued power task is immediately visible without any upstream reads');
check(command('reboot',$r)[0]===409,'conflicting stale operation cannot be enqueued');
reject(fn()=>(new RenewalService())->issue($uid,$iid),'pending power task blocks renewal settlement path');
$db->prepare("UPDATE products SET status='retired' WHERE id=?")->execute([$product]);
$worker->runOnce('power-stop');check(task($tid)['status']==='success'&&runtime()['state']==='off','delisted product still executes original stop and shows real off state');
check(runtime()['actions']===['start'],'only start is enabled when stopped');
$after=[powerValue('SELECT balance FROM users WHERE id=?',[$uid]),powerValue('SELECT price_snapshot FROM invoices WHERE id=?',[$order['invoice_id']]),powerValue('SELECT config_snapshot FROM instances WHERE id=?',[$iid]),powerValue('SELECT due_date FROM instances WHERE id=?',[$iid])];
check($before===$after&&powerValue('SELECT status FROM instances WHERE id=?',[$iid])==='active','power operation changes neither funds, frozen data, term nor lifecycle status');
check(command('stop',$r)[0]===409,'old confirmation remains invalid after its task completed');
[$s,$v]=command('start');$start=$v['data']['task_id'];$worker->runOnce('power-start');check($s===200&&task($start)['status']==='success'&&runtime()['state']==='on','start runs through the same Outbox and confirms current state');
fixture('/__power_mode?mode=deny_stop');check(runtime()['actions']===['reboot'],'individual upstream permission can remove stop without enabling hard shutdown');
fixture('/__power_mode?mode=unsupported');check(runtime()['state']==='unsupported'&&runtime()['actions']===[],'products without power capabilities have no controls');
fixture('/__power_mode?mode=wrong_header');check(runtime()['state']==='unknown'&&runtime()['actions']===[],'mismatched capability host id fails closed');
fixture('/__power_mode?mode=unavailable');check(runtime()['state']==='unknown'&&runtime()['actions']===[],'status outage does not display cached on state as current');
fixture('/__power_mode?mode=normal');
$db->prepare("UPDATE instances SET status='suspended',suspend_type='manual' WHERE id=?")->execute([$iid]);check(runtime()['actions']===[],'manual suspension cannot be bypassed with start');
$db->prepare("UPDATE instances SET status='active',due_date=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE id=?")->execute([$iid]);check(runtime()['actions']===[],'overdue resource cannot bypass billing with power controls');
$db->prepare('UPDATE instances SET due_date=? WHERE id=?')->execute([$before[3],$iid]);
$db->prepare('UPDATE providers SET status=0 WHERE id=?')->execute([$provider]);check(runtime()['actions']===[],'disabled provider fails closed');$db->prepare('UPDATE providers SET status=1 WHERE id=?')->execute([$provider]);
$db->prepare("UPDATE invoices SET status='refund_pending' WHERE id=?")->execute([$order['invoice_id']]);check(runtime()['blocked']&&runtime()['actions']===[],'financial freeze blocks power writes');$db->prepare("UPDATE invoices SET status='paid' WHERE id=?")->execute([$order['invoice_id']]);
$db->prepare("UPDATE invoices SET status='refund_pending' WHERE id=?")->execute([$order['invoice_id']]);$counts=fixture('/__request_counts');
check(http('GET',$localPath,null,$token)[1]['data']['blocked']&&fixture('/__request_counts')===$counts,'local-only power gate retains financial freeze protection without an upstream dependency');
$db->prepare("UPDATE invoices SET status='paid' WHERE id=?")->execute([$order['invoice_id']]);
// A real lost response after executing reboot must never become another reboot.
[$s,$v]=command('reboot');$lost=$v['data']['task_id'];$calls=fixture('/__state')['power_calls'];fixture('/__power_mode?mode=drop');$worker->runOnce('power-drop');
check(task($lost)['status']==='investigating'&&(int)fixture('/__state')['power_calls']===$calls+1,'lost acknowledgement enters investigation after one execution');
check(runtime()['state']==='on'&&runtime()['blocked'],'on state alone does not prove original reboot outcome');
$counts=fixture('/__request_counts');$local=http('GET',$localPath,null,$token)[1]['data'];
check($local['blocked']&&$local['tasks'][0]['id']===$lost&&$local['tasks'][0]['result']==='investigating'&&fixture('/__request_counts')===$counts,'unknown power outcomes remain occupied and trackable with local reads alone');
check(command('reboot')[0]===409,'unknown result keeps the resource occupied');
$db->prepare('UPDATE tasks SET next_retry_at=NULL WHERE id=?')->execute([$lost]);fixture('/__power_mode?mode=normal');$worker->runOnce('power-query');
check(task($lost)['status']==='failed'&&str_starts_with(task($lost)['last_error'],'MANUAL_AUDIT_REQUIRED:'),'unqueryable request becomes durable administrator review');
check((int)fixture('/__state')['power_calls']===$calls+1,'read-only investigation never repeats reboot');
[$s,$view]=http('GET',"/admin/tasks/$lost",null,$at);check($s===200&&$view['data']['allowed_actions']===['investigate','fulfill','close_unexecuted'],'admin sees controlled power resolution without retry or refund');
check(http('GET','/admin/tasks?action=power',null,$at)[0]===200,'admin list supports power filtering');
$resolve=new TaskResolutionService();$t=task($lost);$base=['evidence'=>'Upstream ticket POWER-101 confirms original request accepted','expected_execution_token'=>(int)$t['execution_token']];
reject(fn()=>$resolve->resolve($aid,$lost,$base+['action'=>'retry','confirmed_no_execution_or_pending_request'=>true]),'power manual resolution never enables generic retry');
reject(fn()=>$resolve->resolve($aid,$lost,$base+['action'=>'fulfill','remote_instance_id'=>'host:wrong']),'manual confirmation must retain original resource ownership');
$body=$base+['action'=>'fulfill','remote_instance_id'=>$remote];
$db->prepare("UPDATE admins SET role='support' WHERE id=?")->execute([$aid]);
check(http('POST',"/admin/tasks/$lost/resolve",$body,$at)[0]===403,'current role downgrade prevents power resolution despite an existing token');
$db->prepare("UPDATE admins SET role='super_admin' WHERE id=?")->execute([$aid]);
$db->exec("CREATE TRIGGER test_power_audit_failure BEFORE INSERT ON admin_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated audit failure'");
try {
    try {$resolve->resolve($aid,$lost,$body);throw new RuntimeException('Audit failure was ignored');}
    catch(PDOException) {check(task($lost)['status']==='failed'&&(int)task($lost)['execution_token']===$base['expected_execution_token'],'audit insertion failure rolls back power resolution and its token');}
} finally {$db->exec('DROP TRIGGER test_power_audit_failure');}
$resolve->resolve($aid,$lost,$base+['action'=>'fulfill','remote_instance_id'=>$remote]);check(task($lost)['status']==='success'&&!runtime()['blocked'],'evidenced manual confirmation closes only original operation');
check((int)powerValue("SELECT COUNT(*) FROM admin_audit_logs WHERE target_type='task' AND target_id=? AND action='task_fulfill'",[$lost])===1,'manual power resolution writes immutable audit');
reject(fn()=>$resolve->resolve($aid,$lost,$base+['action'=>'fulfill','remote_instance_id'=>$remote]),'repeated or stale manual resolution rejected');
// Crash after claim: recovery may only query, even if no mutating command actually reached upstream.
[$s,$v]=command('reboot');$crash=$v['data']['task_id'];$claim=$worker->claim('power-crashed');$calls=fixture('/__state')['power_calls'];
$db->prepare('UPDATE tasks SET lease_until=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id=?')->execute([$crash]);
$recovered=$worker->claim('power-recovery');check($recovered['id']==$crash&&$recovered['status']==='investigating'&&$recovered['execution_token']>$claim['execution_token'],'expired claim takes over the original task in investigation mode');
reject(fn()=>$worker->finish($claim,new App\Plugins\Providers\DriverExecutionResult('succeeded',$remote)),'old Worker cannot overwrite recovered power result');
$worker->finish($recovered,new App\Plugins\Providers\DriverExecutionResult('unknown',code:'NON_QUERYABLE'));$t=task($crash);
$base=['evidence'=>'Ticket POWER-102 confirms no execution and no queued request','expected_execution_token'=>(int)$t['execution_token']];
$resolve->resolve($aid,$crash,$base+['action'=>'investigate']);$worker->runOnce('power-read-again');
check((int)fixture('/__state')['power_calls']===$calls&&task($crash)['status']==='failed','admin investigation remains read-only and cannot resubmit');
$t=task($crash);$base['expected_execution_token']=(int)$t['execution_token'];
check(http('POST',"/admin/tasks/$crash/resolve",$base+['action'=>'close_unexecuted'],$at)[0]===400,'no-execution closure requires explicit no-delayed-request confirmation');
$resolve->resolve($aid,$crash,$base+['action'=>'close_unexecuted','confirmed_no_execution_or_pending_request'=>true]);
check(!runtime()['blocked']&&runtime()['tasks'][0]['result']==='not_executed','verified no-execution closure releases occupation without faking accepted command');
check(powerValue('SELECT remote_instance_id FROM instances WHERE id=?',[$iid])===$remote&&powerValue('SELECT due_date FROM instances WHERE id=?',[$iid])===$before[3],'manual resolution preserves resource and billing term');
// State changes between view and execution stop the old request before upstream mutation.
[$s,$v]=command('stop');$changed=$v['data']['task_id'];$calls=fixture('/__state')['power_calls'];fixture('/__power_state?hostid='.$rid.'&state=off');$worker->runOnce('power-precondition');
check(task($changed)['status']==='failed'&&(int)fixture('/__state')['power_calls']===$calls,'Worker rechecks changed remote preconditions and sends no command');
$t=task($changed);$resolve->resolve($aid,$changed,['action'=>'close_unexecuted','evidence'=>'Ticket POWER-103 confirms command was never submitted','expected_execution_token'=>(int)$t['execution_token'],'confirmed_no_execution_or_pending_request'=>true]);
fixture('/__power_state?hostid='.$rid.'&state=on');
// Pending state blocks even when its lease is absent; context changes cannot redirect execution.
[$s,$v]=command('reboot');$binding=$v['data']['task_id'];$db->prepare('UPDATE instances SET remote_instance_id=? WHERE id=?')->execute(['host:999999',$iid]);$calls=fixture('/__state')['power_calls'];$worker->runOnce('power-binding');
check(task($binding)['status']==='failed'&&(int)fixture('/__state')['power_calls']===$calls,'frozen provider/resource binding cannot be redirected by an instance edit');
$db->prepare('UPDATE instances SET remote_instance_id=? WHERE id=?')->execute([$remote,$iid]);$t=task($binding);$resolve->resolve($aid,$binding,['action'=>'close_unexecuted','evidence'=>'Ticket POWER-104 original command not submitted or queued','expected_execution_token'=>(int)$t['execution_token'],'confirmed_no_execution_or_pending_request'=>true]);
// A non-success module response can conceal a completed operation; it is not safe retry evidence.
[$s,$v]=command('reboot');$business=$v['data']['task_id'];$calls=fixture('/__state')['power_calls'];fixture('/__power_mode?mode=business_error');$worker->runOnce('power-module-error');
check(task($business)['status']==='investigating'&&(int)fixture('/__state')['power_calls']===$calls+1,'business failure after execution is treated as unknown rather than safe to retry');
$db->prepare('UPDATE tasks SET next_retry_at=NULL WHERE id=?')->execute([$business]);fixture('/__power_mode?mode=normal');$worker->runOnce('power-module-query');$t=task($business);
$resolve->resolve($aid,$business,['action'=>'fulfill','evidence'=>'Ticket POWER-105 confirms original reboot was accepted upstream','expected_execution_token'=>(int)$t['execution_token'],'remote_instance_id'=>$remote]);
check((int)fixture('/__state')['power_calls']===$calls+1,'module-error investigation and audited completion never repeat command');
// Safe browser fixture: leave a real synthetic cloud resource, no pending mutation or external dependency.
$db->prepare("UPDATE products SET status='active' WHERE id=?")->execute([$product]);
$db->prepare("UPDATE instances SET name='网页电源验收实例' WHERE id=?")->execute([$iid]);
echo "Power checks: $checks\n";
