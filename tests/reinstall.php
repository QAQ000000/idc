<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
set_exception_handler(static function(Throwable $e):never{fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
use App\Core\{Database,Security,ApiException};
use App\Services\{AuthService,AdminAuthService,BillingService,OutboxWorker,ProductSnapshotService,TaskResolutionService,RenewalService,InstanceService};
use App\Plugins\Providers\DriverExecutionResult;
if(getenv('APP_ENV')!=='test'||getenv('DB_DATABASE')!=='jianyun_test'){throw new RuntimeException('Isolated test environment required');}
$db=Database::getInstance();$checks=0;
function check(bool $v,string $m):void{global $checks;if(!$v){throw new RuntimeException('FAIL reinstall: '.$m);}$checks++;echo "PASS reinstall: $m\n";}
function reinstallValue(string $sql,array $p=[]):mixed{global $db;$s=$db->prepare($sql);$s->execute($p);return $s->fetchColumn();}
function fixture(string $path):array{return json_decode(file_get_contents(getenv('UPSTREAM_TEST_URL').$path),true,64,JSON_THROW_ON_ERROR);}
function http(string $method,string $path,?array $body=null,?string $token=null):array{
 $c=curl_init(getenv('TEST_BASE_URL').'/api/v1'.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_TIMEOUT=>25,CURLOPT_HTTPHEADER=>array_filter(['Content-Type: application/json',$token?'Authorization: Bearer '.$token:null])]);if($body!==null){curl_setopt($c,CURLOPT_POSTFIELDS,json_encode($body));}$raw=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);return [$status,json_decode($raw,true,64,JSON_THROW_ON_ERROR)];
}
function reinstallView():array{global $iid,$token;[$s,$r]=http('GET',"/instances/$iid/reinstall",null,$token);if($s!==200){throw new RuntimeException('Options failed');}return $r['data'];}
function body(?array $v=null):array{return ['image_id'=>'1173023','operation_token'=>($v??reinstallView())['operation_token'],'request_key'=>bin2hex(random_bytes(16)),'confirmed_system_disk_erase'=>true];}
function submit(?array $b=null):array{global $iid,$token;return http('POST',"/instances/$iid/reinstall",$b??body(),$token);}
function task(int $id):array{global $db;$s=$db->prepare('SELECT * FROM tasks WHERE id=?');$s->execute([$id]);return $s->fetch();}
function rejects(callable $f,string $m):void{try{$f();}catch(ApiException $e){check($e->httpStatus===409,$m);return;}throw new RuntimeException('Expected rejection: '.$m);}
function signed(array $c):string{$b=rtrim(strtr(base64_encode(json_encode($c)),'+/','-_'),'=');return $b.'.'.Security::requestFingerprint('reinstall:'.$b);}
function manual(int $tid,string $action,array $extra=[]):void{global $aid;(new TaskResolutionService())->resolve($aid,$tid,['action'=>$action,'expected_execution_token'=>(int)task($tid)['execution_token'],'evidence'=>'Isolated upstream ticket R-101 confirms original operation and credentials']+$extra);}
function phase(string $phase):void{global $remote;fixture('/__reinstall_phase?hostid='.substr($remote,5).'&phase='.$phase);}
function expireInvestigation(int $tid):void{global $db;$db->prepare("UPDATE tasks SET next_retry_at=NULL,payload=JSON_SET(payload,'$.investigation_started_at',?) WHERE id=?")->execute([time()-3601,$tid]);}
function poll(int $tid,string $worker):void{global $db,$workerService;$db->prepare('UPDATE tasks SET next_retry_at=NULL WHERE id=?')->execute([$tid]);$workerService->runOnce($worker);}
function closeTask(int $tid):void{manual($tid,'close_unexecuted',['confirmed_no_execution_or_pending_request'=>true]);phase('idle');}
function runToReview(string $worker):void{global $db,$workerService;$workerService->runOnce($worker);$db->exec("UPDATE tasks SET next_retry_at=NULL,payload=JSON_SET(payload,'$.investigation_started_at',UNIX_TIMESTAMP()-3601) WHERE action='reinstall'");$workerService->runOnce($worker.'-query');}
$db->exec("UPDATE tasks SET status='success',lease_until=NULL WHERE status IN ('pending','running','investigating')");
fixture('/__power_mode?mode=normal');fixture('/__reinstall_mode?mode=normal');
$count=reinstallValue('SELECT COUNT(*) FROM tasks');$migration=require dirname(__DIR__).'/database/migrations/2026_11_08_000008_reinstall_operations.php';$migration->up();$migration->up();
check(reinstallValue('SELECT COUNT(*) FROM tasks')===$count&&str_contains($db->query("SHOW COLUMNS FROM tasks LIKE 'action'")->fetch()['Type'],"'reinstall'"),'forward migration after old power migration preserves tasks and is rerunnable');
$auth=(new AuthService())->register(['email'=>'reinstall@example.test','password'=>'ReinstallTest!2026']);$uid=(int)$auth['user']['id'];$token=Security::generateToken(['uid'=>$uid]);
$db->prepare('UPDATE users SET balance=100 WHERE id=?')->execute([$uid]);
$db->prepare('INSERT INTO providers(name,driver,endpoint,credentials) VALUES(?,?,?,?)')->execute(['reinstall-fixture','zjmf',getenv('UPSTREAM_TEST_URL'),json_encode(['username'=>Security::encrypt('fixture'),'password'=>Security::encrypt('fixture-secret')])]);$provider=(int)$db->lastInsertId();
$product=(new ProductSnapshotService())->save(['provider_id'=>$provider,'remote_pid'=>391,'name'=>'Reinstall fixture','type'=>'cloud','billing_cycles'=>['monthly']])['product_id'];
$order=(new BillingService())->checkout($uid,['product_id'=>$product,'hostname'=>'Reinstall8','password'=>'ReinstallRoot!2026','billing_cycle'=>'monthly','use_balance'=>true]);
$iid=(int)$order['instance_id'];$workerService=new OutboxWorker();$workerService->runOnce('reinstall-create');
$remote=reinstallValue('SELECT remote_instance_id FROM instances WHERE id=?',[$iid]);
$admin=(new AdminAuthService())->create(['username'=>'reinstall-admin','email'=>'reinstall-admin@example.test','password'=>'ReinstallAdmin!2026','role'=>'super_admin']);$aid=is_array($admin)?(int)$admin['id']:(int)$admin;$at=Security::generateToken(['uid'=>$aid,'sub_type'=>'admin']);
$other=(new AuthService())->register(['email'=>'reinstall-other@example.test','password'=>'OtherReinstall!2026']);$ot=Security::generateToken(['uid'=>(int)$other['user']['id']]);
check(http('GET',"/instances/$iid/reinstall")[0]===401,'anonymous cannot inspect');
check(http('GET',"/instances/$iid/reinstall",null,$at)[0]===401,'admin scope cannot impersonate owner API');
check(http('GET',"/instances/$iid/reinstall",null,$ot)[0]===404,'foreign customer cannot inspect');
check(http('GET',"/instances/$iid/reinstall?view=tasks")[0]===401,'local task tracking still requires authentication');
check(http('GET',"/instances/$iid/reinstall?view=tasks",null,$at)[0]===401,'administrator token cannot read owner task tracking');
check(http('GET',"/instances/$iid/reinstall?view=tasks",null,$ot)[0]===404,'local task tracking retains resource ownership isolation');
check(http('GET',"/instances/$iid/reinstall?view=other",null,$token)[0]===400&&http('GET',"/instances/$iid/reinstall?view[]=tasks",null,$token)[0]===400,'unknown and array tracking modes are rejected');
$counts=fixture('/__request_counts');
[$s,$tracking]=http('GET',"/instances/$iid/reinstall?view=tasks",null,$token);
check($s===200&&$tracking['data']===['blocked'=>false,'tasks'=>[]]&&fixture('/__request_counts')===$counts,'empty history tracking reads MySQL only without calling or logging into upstream');
$v=reinstallView();$b=body($v);
check($v['available']&&$v['images'][0]['id']==='1173023'&&$v['password_mode']==='upstream_generated'&&$v['data_disk']==='no_format_requested','explicit cloud capability supplies upstream image ids and honest credential/disk modes');
check(!str_contains(json_encode($v),'SECRET')&&!str_contains(json_encode($v),'fixture-secret')&&!str_contains(json_encode($v),'endpoint'),'safe projection hides provider metadata and passwords');
check(http('POST',"/instances/$iid/reinstall",$b,$ot)[0]===409,'owner-bound token cannot cross customers');
$imp=Security::generateToken(['uid'=>$uid,'is_impersonated'=>true,'operator_admin_id'=>$aid]);check(http('POST',"/instances/$iid/reinstall",$b,$imp)[0]===403,'impersonation cannot submit destructive operations');
$bad=$b;$bad['confirmed_system_disk_erase']=false;check(submit($bad)[0]===400,'system disk erasure acknowledgement required');
$bad=$b;$bad['new_password']='ShouldNeverBeIgnored';check(submit($bad)[0]===400,'custom password is rejected rather than silently ignored');
$bad=$b;$bad['format_data_disk']=1;check(submit($bad)[0]===400,'caller cannot request data disk formatting');
$bad=$b;$bad['image_id']='999999';check(submit($bad)[0]===409,'invented image rejected');
$bad=$b;$bad['operation_token'].='x';check(submit($bad)[0]===409,'tampered confirmation rejected');
$c=json_decode(base64_decode(strtr(explode('.',$b['operation_token'])[0],'-_','+/')),true);$c['expires']=time()-1;$bad=$b;$bad['operation_token']=signed($c);check(submit($bad)[0]===409,'expired confirmation rejected');
fixture('/__reinstall_mode?mode=schema');check(submit($b)[0]===409,'changed upstream images require renewed confirmation');fixture('/__reinstall_mode?mode=normal');
fixture('/__reinstall_mode?mode=unsupported');check(!reinstallView()['available'],'non-cloud module is not guessed compatible');fixture('/__reinstall_mode?mode=wrong_header');check(!reinstallView()['available'],'resource identity mismatch fails closed');fixture('/__reinstall_mode?mode=unavailable');check(reinstallView()['state']==='unavailable','capability outage is explicit');fixture('/__reinstall_mode?mode=normal');
$db->prepare("UPDATE instances SET status='suspended',suspend_type='manual' WHERE id=?")->execute([$iid]);check(!reinstallView()['available'],'manual suspension cannot be bypassed');$db->prepare("UPDATE instances SET status='active' WHERE id=?")->execute([$iid]);
$db->prepare('UPDATE providers SET status=0 WHERE id=?')->execute([$provider]);check(!reinstallView()['available'],'disabled provider not available');$db->prepare('UPDATE providers SET status=1 WHERE id=?')->execute([$provider]);
$db->prepare("UPDATE invoices SET status='refund_pending' WHERE id=?")->execute([$order['invoice_id']]);check(reinstallView()['blocked']&&!reinstallView()['available'],'financial freeze blocks reinstall');$db->prepare("UPDATE invoices SET status='paid' WHERE id=?")->execute([$order['invoice_id']]);
$baseline=[];foreach(['provider_id','remote_instance_id','user_id','product_id','billing_cycle','due_date','recurring_price','override_price','status'] as $k){$baseline[$k]=reinstallValue("SELECT $k FROM instances WHERE id=?",[$iid]);}
$money=[reinstallValue('SELECT balance FROM users WHERE id=?',[$uid]),reinstallValue('SELECT COUNT(*) FROM invoices'),reinstallValue('SELECT COUNT(*) FROM transactions'),reinstallValue('SELECT price_snapshot FROM invoices WHERE id=?',[$order['invoice_id']])];
$b=body();$multi=curl_multi_init();$handles=[];
for($n=0;$n<4;$n++){$ch=curl_init(getenv('TEST_BASE_URL')."/api/v1/instances/$iid/reinstall");curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($b),CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$token]]);curl_multi_add_handle($multi,$ch);$handles[]=$ch;}
do{curl_multi_exec($multi,$active);if($active){curl_multi_select($multi,1);}}while($active);$codes=[];$tid=0;foreach($handles as $ch){$codes[]=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$data=json_decode(curl_multi_getcontent($ch),true);if(isset($data['data']['task_id'])){$tid=(int)$data['data']['task_id'];}curl_multi_remove_handle($multi,$ch);curl_close($ch);}curl_multi_close($multi);sort($codes);
check($codes===[200,409,409,409]&&$tid>0,'four concurrent submissions create exactly one task');
check(task($tid)['invoice_id']===null&&(int)task($tid)['max_retries']===0,'nonfinancial Outbox never enables automatic resend');
check(reinstallView()['blocked']&&!reinstallView()['available'],'pending reinstall holds business gate');
$counts=fixture('/__request_counts');fixture('/__reinstall_mode?mode=unavailable');
[$s,$tracking]=http('GET',"/instances/$iid/reinstall?view=tasks",null,$token);
check($s===200&&$tracking['data']['blocked']&&$tracking['data']['tasks'][0]['id']===$tid&&fixture('/__request_counts')===$counts,'queued task tracking remains available without querying unavailable upstream capabilities');
check(array_keys($tracking['data'])===['blocked','tasks']&&!str_contains(json_encode($tracking),'password')&&!str_contains(json_encode($tracking),'SECRET'),'tracking omits credentials, images and operation confirmation tokens');
fixture('/__reinstall_mode?mode=normal');
check(http('GET',"/instances/$iid/credentials",null,$token)[0]===409,'old credentials hidden before any upstream call');
rejects(fn()=>(new RenewalService())->issue($uid,$iid),'pending reinstall blocks renewal');
$r=http('GET',"/instances/$iid/runtime",null,$token)[1]['data'];check($r['blocked']&&$r['actions']===[],'pending reinstall blocks power actions');
$db->prepare("UPDATE products SET status='retired' WHERE id=?")->execute([$product]);
$calls=fixture('/__state')['reinstall_calls'];$workerService->runOnce('reinstall-first');
check(task($tid)['status']==='investigating'&&fixture('/__state')['reinstall_calls']===$calls+1,'Worker sends one request even after catalog retirement; acceptance is not success');
$sent=fixture('/__state')['last_reinstall'];check($sent['format_data_disk']===['0']&&$sent['os']===['1173023']&&!isset($sent['password']),'driver sends original image and fixed preserve request without unsupported password');
check(reinstallView()['tasks'][0]['accepted']&&reinstallView()['tasks'][0]['result']==='executing','customer sees actual acknowledgement without invented percent');
$db->prepare('UPDATE tasks SET next_retry_at=NULL WHERE id=?')->execute([$tid]);$workerService->runOnce('reinstall-query');
check(task($tid)['status']==='investigating'&&reinstallView()['tasks'][0]['result']==='executing','normal accepted execution remains automatic rather than requiring routine administrator verification');
check((int)reinstallValue("SELECT COUNT(*) FROM tasks WHERE id=? AND next_retry_at<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 16 SECOND)",[$tid])===1,'accepted reinstall schedules bounded fifteen second read-only polling');
check(http('GET',"/admin/tasks?status=attention&task_id=$tid",null,$at)[1]['data']['total']===0,'healthy accepted execution does not appear as an abnormal task');
expireInvestigation($tid);$workerService->runOnce('reinstall-stalled');
check(task($tid)['status']==='failed'&&str_starts_with(task($tid)['last_error'],'MANUAL_AUDIT_REQUIRED:'),'stalled execution is an exceptional persistent administrator review');
check(fixture('/__state')['reinstall_calls']===$calls+1,'read-only investigation cannot resend');
check(http('GET',"/instances/$iid/credentials",null,$token)[0]===409,'unresolved credentials remain hidden');
$detail=http('GET',"/admin/tasks/$tid",null,$at)[1]['data'];check($detail['allowed_actions']===['investigate','fulfill','close_unexecuted'],'manual actions exclude retry, refund and create cancellation');
check(http('GET','/admin/tasks?action=reinstall',null,$at)[0]===200,'administrator list filters reinstall');
rejects(fn()=>manual($tid,'retry',['confirmed_no_execution_or_pending_request'=>true]),'generic manual retry unavailable');
rejects(fn()=>manual($tid,'cancel_compensate',['confirmed_no_execution_or_pending_request'=>true]),'new-purchase cancel cannot destroy existing resource');
rejects(fn()=>manual($tid,'fulfill',['remote_instance_id'=>'host:wrong','username'=>'root','new_password'=>'VerifiedNew!2026','confirmed_reinstall_completed'=>true]),'wrong resource cannot be substituted');
$complete=['remote_instance_id'=>$remote,'username'=>'root','new_password'=>'VerifiedNew!2026','confirmed_reinstall_completed'=>true];
$bad=$complete;$bad['confirmed_reinstall_completed']=false;check(http('POST',"/admin/tasks/$tid/resolve",['action'=>'fulfill','expected_execution_token'=>(int)task($tid)['execution_token'],'evidence'=>'Verified original reinstall evidence']+$bad,$at)[0]===400,'actual completion acknowledgement required');
$db->prepare("UPDATE admins SET role='support' WHERE id=?")->execute([$aid]);check(http('POST',"/admin/tasks/$tid/resolve",['action'=>'fulfill','expected_execution_token'=>(int)task($tid)['execution_token'],'evidence'=>'Verified original reinstall evidence']+$complete,$at)[0]===403,'live role downgrade rejects credential backfill');$db->prepare("UPDATE admins SET role='super_admin' WHERE id=?")->execute([$aid]);
$db->exec("CREATE TRIGGER test_reinstall_audit_failure BEFORE INSERT ON admin_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated audit failure'");
$oldPassword=reinstallValue('SELECT encrypted_password FROM instances WHERE id=?',[$iid]);
try{try{manual($tid,'fulfill',$complete);throw new RuntimeException('Audit failure ignored');}catch(PDOException){check(task($tid)['status']==='failed'&&reinstallValue('SELECT encrypted_password FROM instances WHERE id=?',[$iid])===$oldPassword,'audit failure rolls back credentials and terminal task');}}finally{$db->exec('DROP TRIGGER test_reinstall_audit_failure');}
$oldToken=(int)task($tid)['execution_token'];manual($tid,'fulfill',$complete);phase('idle');
check(task($tid)['status']==='success'&&(int)task($tid)['execution_token']>$oldToken,'verified completion fences old workers and marks original task');
$credentials=(new InstanceService())->credentials($uid,$iid);check($credentials['password']==='VerifiedNew!2026'&&$credentials['username']==='root','verified generated credentials available to owner');
check(reinstallValue('SELECT encrypted_password FROM instances WHERE id=?',[$iid])!=='VerifiedNew!2026','new password encrypted at rest');
check((new InstanceService())->detail($uid,$iid)['reinstalled_image']==='Debian-12.0_x64','confirmed installed image distinct from original sales snapshot');
$public=json_encode(http('GET',"/admin/tasks/$tid",null,$at));check(!str_contains($public,'VerifiedNew')&&!str_contains($public,'HEADER-PASSWORD'),'task detail and audit exclude all credentials');
foreach($baseline as $k=>$before){check(reinstallValue("SELECT $k FROM instances WHERE id=?",[$iid])===$before,'preserve original '.$k);}
check($money===[reinstallValue('SELECT balance FROM users WHERE id=?',[$uid]),reinstallValue('SELECT COUNT(*) FROM invoices'),reinstallValue('SELECT COUNT(*) FROM transactions'),reinstallValue('SELECT price_snapshot FROM invoices WHERE id=?',[$order['invoice_id']])],'reinstall creates no financial side effects');
check(submit($b)[0]===409,'original confirmation invalid after terminal outcome');
// Lost response may have already wiped disk: never repeat. Manual close requires explicit nonexecution evidence.
[$status,$r]=submit();$lost=(int)$r['data']['task_id'];fixture('/__reinstall_mode?mode=drop');runToReview('reinstall-lost');
check(task($lost)['status']==='failed'&&fixture('/__state')['reinstall_calls']===$calls+2,'timeout after execution becomes review after one mutation');
manual($lost,'investigate');$workerService->runOnce('reinstall-admin-query');check(task($lost)['status']==='investigating'&&fixture('/__state')['reinstall_calls']===$calls+2,'manual investigation is strictly read-only');expireInvestigation($lost);$workerService->runOnce('reinstall-admin-query-timeout');
fixture('/__reinstall_mode?mode=normal');closeTask($lost);check(!reinstallView()['blocked']&&(new InstanceService())->credentials($uid,$iid)['password']==='VerifiedNew!2026','evidenced nonexecution closes only operation and preserves saved credentials');
// Worker dies after claim, possibly before sending. Recovery cannot know which; it must investigate.
[$s,$r]=submit();$crash=(int)$r['data']['task_id'];$claim=$workerService->claim('reinstall-crashed');
$db->prepare('UPDATE tasks SET lease_until=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id=?')->execute([$crash]);
$recovered=$workerService->claim('reinstall-recovered');check((int)$recovered['id']===$crash&&$recovered['status']==='investigating'&&$recovered['execution_token']>$claim['execution_token'],'crash recovery retains identity with a new fencing token');
rejects(fn()=>$workerService->finish($claim,new DriverExecutionResult('succeeded',$remote,username:'root',password:'StalePassword!2026')),'old Worker cannot overwrite recovered credentials');
$workerService->finish($recovered,new DriverExecutionResult('unknown',code:'NON_QUERYABLE'));closeTask($crash);
rejects(fn()=>$workerService->finish($recovered,new DriverExecutionResult('succeeded',$remote,username:'root',password:'StalePassword!2026')),'old Worker cannot overwrite manual terminal outcome');
check(fixture('/__state')['reinstall_calls']===$calls+2,'claim recovery does not execute another destructive request');
// Options can change between customer commit and Worker execution.
[$s,$r]=submit();$changed=(int)$r['data']['task_id'];fixture('/__reinstall_mode?mode=schema');$workerService->runOnce('reinstall-schema-changed');
check(task($changed)['status']==='failed'&&fixture('/__state')['reinstall_calls']===$calls+2,'Worker rechecks options and refuses changed capability before mutation');fixture('/__reinstall_mode?mode=normal');closeTask($changed);
// Normal acknowledged execution automatically ends after a real observed task -> idle transition.
[$s,$r]=submit();$automatic=(int)$r['data']['task_id'];$normalCalls=fixture('/__state')['reinstall_calls'];
$workerService->runOnce('reinstall-automatic');
check(reinstallView()['tasks'][0]['result']==='executing'&&reinstallView()['blocked'],'known accepted execution remains gated while upstream works');
phase('idle');fixture('/__reinstall_mode?mode=missing_credentials');poll($automatic,'reinstall-await-credentials');
check(task($automatic)['status']==='investigating'&&http('GET',"/instances/$iid/credentials",null,$token)[0]===409,'idle without current credentials does not close the task or expose an old password');
fixture('/__reinstall_mode?mode=wrong_header');poll($automatic,'reinstall-wrong-binding');
check(task($automatic)['status']==='investigating','changed resource identity cannot backfill credentials or end occupation');
fixture('/__reinstall_mode?mode=normal');
// Crash during a read-only query retains accepted/executing checkpoints and recovers without another mutation.
$db->prepare('UPDATE tasks SET next_retry_at=NULL WHERE id=?')->execute([$automatic]);$stale=$workerService->claim('reinstall-poll-crash');
$db->prepare('UPDATE tasks SET lease_until=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id=?')->execute([$automatic]);
$workerService->runOnce('reinstall-poll-recovery');
check(task($automatic)['status']==='success'&&reinstallView()['tasks'][0]['result']==='execution_ended'&&!reinstallView()['blocked'],'observed execution returning idle automatically ends original task and releases gate');
$cred=(new InstanceService())->credentials($uid,$iid);
check($cred['password']==='NEVER-EXPOSE-NEW-HEADER-PASSWORD'&&$cred['verification']==='upstream_reported','automatic sync encrypts upstream credentials without claiming tested login');
check((new InstanceService())->detail($uid,$iid)['reinstalled_image']===null,'execution ending does not claim a confirmed installed OS or retain an obsolete one');
check(fixture('/__state')['reinstall_calls']===$normalCalls+1,'polling, missing credentials and recovery cause exactly one destructive request');
check(http('GET',"/admin/tasks/$automatic",null,$at)[1]['data']['task']['reinstall_result']==='execution_ended','administrator projection distinguishes execution end from verified OS success');
check(!str_contains(json_encode(http('GET',"/admin/tasks/$automatic",null,$at)),'HEADER-PASSWORD'),'automatic outcome never leaks credentials in administrator task context');
rejects(fn()=>$workerService->finish($stale,new DriverExecutionResult('succeeded',$remote,username:'root',password:'Stale!2026')),'stale poll cannot replace automatically synchronized credentials');
foreach($baseline as $k=>$before){check(reinstallValue("SELECT $k FROM instances WHERE id=?",[$iid])===$before,'automatic flow preserves original '.$k);}
check($money===[reinstallValue('SELECT balance FROM users WHERE id=?',[$uid]),reinstallValue('SELECT COUNT(*) FROM invoices'),reinstallValue('SELECT COUNT(*) FROM transactions'),reinstallValue('SELECT price_snapshot FROM invoices WHERE id=?',[$order['invoice_id']])],'automatic flow has no financial effects');
// A fast/missed execution cannot be inferred from idle, accepted metadata, or a previous task checkpoint.
fixture('/__reinstall_mode?mode=missed_execution');[$s,$r]=submit();$missed=(int)$r['data']['task_id'];$workerService->runOnce('reinstall-missed');poll($missed,'reinstall-missed-query');
check(task($missed)['status']==='investigating'&&reinstallView()['tasks'][0]['result']==='accepted','accepted but never observed execution remains honestly waiting, not success or immediate review');
phase('unrelated');poll($missed,'reinstall-unrelated');phase('idle');poll($missed,'reinstall-after-unrelated');
check(task($missed)['status']==='investigating'&&!isset(json_decode(task($missed)['payload'],true)['reinstall_execution_observed']),'unrelated cloud task must not become proof of this reinstall');
phase('generic');poll($missed,'reinstall-generic');phase('idle');poll($missed,'reinstall-after-generic');
check(task($missed)['status']==='investigating'&&!isset(json_decode(task($missed)['payload'],true)['reinstall_execution_observed']),'supplier-sanitized generic process description cannot prove this reinstall executed');
expireInvestigation($missed);$workerService->runOnce('reinstall-missed-timeout');closeTask($missed);fixture('/__reinstall_mode?mode=normal');
// Lost acknowledgement must remain uncertain even if a reinstall task and idle are visible.
[$s,$r]=submit();$unknown=(int)$r['data']['task_id'];fixture('/__reinstall_mode?mode=drop');$workerService->runOnce('reinstall-unknown');poll($unknown,'reinstall-unknown-executing');phase('idle');poll($unknown,'reinstall-unknown-idle');
check(task($unknown)['status']==='investigating'&&!reinstallView()['tasks'][0]['accepted'],'read-only lifecycle observations never invent a lost acknowledgement');
expireInvestigation($unknown);$workerService->runOnce('reinstall-unknown-timeout');closeTask($unknown);fixture('/__reinstall_mode?mode=normal');
// An existing remote job prevents a new destructive submission even if local gates are clear.
[$s,$r]=submit();$busy=(int)$r['data']['task_id'];phase('unrelated');$beforeCalls=fixture('/__state')['reinstall_calls'];$workerService->runOnce('reinstall-preflight-busy');
check(task($busy)['status']==='failed'&&fixture('/__state')['reinstall_calls']===$beforeCalls,'upstream busy preflight refuses a new wipe before any destructive request');closeTask($busy);
// Existing tasks from the previous adapter lack the preflight checkpoint; never synthesize it.
[$s,$r]=submit();$legacy=(int)$r['data']['task_id'];$db->prepare("UPDATE tasks SET status='investigating',payload=JSON_SET(payload,'$.reinstall_accepted',true) WHERE id=?")->execute([$legacy]);phase('executing');poll($legacy,'reinstall-legacy');phase('idle');poll($legacy,'reinstall-legacy-idle');
check(task($legacy)['status']==='investigating'&&!isset(json_decode(task($legacy)['payload'],true)['reinstall_monitor_version']),'legacy acknowledgements do not acquire invented preflight evidence');expireInvestigation($legacy);$workerService->runOnce('reinstall-legacy-timeout');closeTask($legacy);
// Execution-end contract cannot bypass its durable checkpoints.
[$s,$r]=submit();$noEvidence=(int)$r['data']['task_id'];$claim=$workerService->claim('reinstall-no-evidence');
rejects(fn()=>$workerService->finish($claim,new DriverExecutionResult('succeeded',$remote,code:'REINSTALL_EXECUTION_ENDED',username:'root',password:'False!2026')),'execution-end result requires durable acknowledgement and observed execution');
$workerService->finish($claim,new DriverExecutionResult('unknown',code:'NON_QUERYABLE'));closeTask($noEvidence);
// Exercise shared atomic completion for a future correlated driver. This is NOT a Magic protocol claim.
[$s,$r]=submit();$verified=(int)$r['data']['task_id'];$claim=$workerService->claim('reinstall-correlated-contract');
$workerService->finish($claim,new DriverExecutionResult('succeeded',$remote,username:'root',password:'Correlated!2026'));
check(task($verified)['status']==='success'&&(new InstanceService())->credentials($uid,$iid)['password']==='Correlated!2026','operation-correlated contract completion atomically stores encrypted credentials');
check(!str_contains(json_encode(reinstallView()),'Correlated!2026'),'owner operation history contains no password');
fixture('/__reinstall_mode?mode=normal');
echo "Reinstall checks: $checks\n";
