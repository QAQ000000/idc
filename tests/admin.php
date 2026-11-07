<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
set_exception_handler(static function (Throwable $e): never { fwrite(STDERR, $e->getMessage()."\n".$e->getTraceAsString()."\n"); exit(1); });
use App\Core\{Database,Security};
use App\Services\{AdminAuthService,AuthService,BillingService,DemoService,TaskInspectionService};
use Illuminate\Support\Facades\RateLimiter;
if (getenv('APP_ENV')!=='test' || getenv('DB_DATABASE')!=='jianyun_test' || !preg_match('#^http://127\.0\.0\.1:[0-9]+$#D',getenv('TEST_BASE_URL')?:'')) { throw new RuntimeException('Isolated database and HTTP server required'); }
$db=Database::getInstance(); $checks=0;
function check(bool $v,string $msg):void { global $checks; if(!$v){throw new RuntimeException('FAIL admin: '.$msg);} $checks++; echo "PASS admin: {$msg}\n"; }
function http(string $method,string $path,?array $body=null,?string $token=null):array {
    $h=curl_init(getenv('TEST_BASE_URL').'/api/v1'.$path);
    curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>array_filter(['Content-Type: application/json',$token?'Authorization: Bearer '.$token:null])]);
    if($body!==null){curl_setopt($h,CURLOPT_POSTFIELDS,json_encode($body,JSON_THROW_ON_ERROR));}
    $raw=curl_exec($h);$status=curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);
    return [$status,json_decode($raw,true,32,JSON_THROW_ON_ERROR)];
}
function avalue(string $sql,array $args=[]):mixed { global $db; $s=$db->prepare($sql);$s->execute($args);return $s->fetchColumn(); }
function manual():array {
    global $db,$uid,$product;
    $i=(new BillingService())->checkout($uid,['product_id'=>$product,'hostname'=>'admin-fixture','password'=>'IsolatedRoot!2026','billing_cycle'=>'monthly','use_balance'=>true]);
    $s=$db->prepare("UPDATE tasks SET status='failed',execution_token=7,last_error='MANUAL_AUDIT_REQUIRED:NON_QUERYABLE',upstream_task_id='host:fixture',payload=JSON_SET(payload,'$.secret','DO_NOT_EXPOSE_PAYLOAD') WHERE invoice_id=?");$s->execute([$i['invoice_id']]);
    $i['task_id']=(int)avalue('SELECT id FROM tasks WHERE invoice_id=?',[$i['invoice_id']]);return $i;
}
$login=['username'=>'web-admin-test','password'=>'AdminFixture!2026'];
$service=new AdminAuthService();
$aid=$service->create($login+['email'=>'web-admin-test@example.test','role'=>'super_admin']);
$rid=$service->create(['username'=>'web-support-test','password'=>$login['password'],'email'=>'web-support-test@example.test','role'=>'support']);
$session=(new AuthService())->register(['email'=>'admin-client-test@example.test','password'=>'ClientFixture!2026']);$uid=(int)$session['user']['id'];$client=$session['token'];
$demo=new DemoService();$demo->recharge($uid,'1000.00','admin-web-test');$product=$demo->seed();
$first=manual();$second=manual();
$rateKey='admin-login:'.Security::requestFingerprint('127.0.0.1');RateLimiter::clear($rateKey);
// Login reuses captcha: enabling policy must block a password-only admin login.
$db->prepare("INSERT INTO system_settings(`key`,value) VALUES('captcha_scenes',?) ON DUPLICATE KEY UPDATE value=VALUES(value)")->execute([json_encode(['login'=>['enabled'=>true,'driver'=>'builtin_image','mode'=>'always']])]);
check(http('POST','/admin/auth/login',$login)[0]===422,'admin login enforces login captcha');
$db->prepare("UPDATE system_settings SET value=? WHERE `key`='captcha_scenes'")->execute([json_encode(['login'=>['enabled'=>false],'register'=>['enabled'=>false]])]);
check(http('POST','/admin/auth/login',['username'=>$login['username'],'password'=>'wrong'])[0]===401,'wrong admin password rejected');
check(http('POST','/admin/auth/login',['username'=>'missing-admin','password'=>'wrong'])[0]===401,'unknown administrator returns generic unauthorized');
[$status,$result]=http('POST','/admin/auth/login',$login+['role'=>'client']);$admin=$result['data']['token']??'';
check($status===200 && Security::verifyToken($admin)['sub_type']==='admin','real admin login issues admin scope independent of input role');
check(!str_contains(json_encode($result),'two_factor') && !str_contains(json_encode($result),'password'),'login response contains no password or second-factor secret');
check(http('GET','/admin/auth/me',null,$admin)[1]['data']['role']==='super_admin','me returns current database role');
check(http('POST','/admin/auth/login',['username'=>'admin-client-test@example.test','password'=>'ClientFixture!2026'])[0]===401,'customer cannot log in through administrator endpoint');
check(http('GET','/admin/tasks')[0]===401 && http('GET','/admin/tasks',null,$client)[0]===401,'anonymous and customer tokens cannot list administrator tasks');
check(http('GET','/admin/tasks/'.$first['task_id'],null,$client)[0]===401,'customer cannot read task context');
check(http('GET','/auth/me',null,$admin)[0]===401,'administrator token cannot enter customer identity scope');
$expired=Security::generateToken(['uid'=>$aid,'sub_type'=>'admin'],-1);
check(http('GET','/admin/tasks',null,$expired)[0]===401,'expired admin token rejected');
$reader=Security::generateToken(['uid'=>$rid,'sub_type'=>'admin']);
check(http('GET','/admin/tasks',null,$reader)[0]===200,'active support may inspect exceptions');
check(http('GET','/admin/tasks/'.$first['task_id'],null,$reader)[1]['data']['allowed_actions']===[],'read-only administrator gets no resolution buttons');
$body=['action'=>'fulfill','evidence'=>'OPS-101: upstream verified original asset ownership and delivery','expected_execution_token'=>7,'remote_instance_id'=>'admin:asset-one'];
$path='/admin/tasks/'.$first['task_id'].'/resolve';
check(http('POST',$path,$body,$reader)[0]===403,'support cannot resolve via direct HTTP request');
check(http('POST',$path,$body,$client)[0]===401,'customer cannot forge an administrator resolution');
$db->prepare("UPDATE admins SET role='operator' WHERE id=?")->execute([$aid]);
check(http('POST',$path,$body,$admin)[0]===403,'role downgrade takes effect even for existing signed token');
$db->prepare("UPDATE admins SET role='super_admin',status='disabled' WHERE id=?")->execute([$aid]);
check(http('GET','/admin/tasks',null,$admin)[0]===403 && http('POST',$path,$body,$admin)[0]===403,'disabled administrator loses read and write access immediately');
check(http('POST','/admin/auth/login',$login)[0]===403,'disabled administrator cannot log in');
$db->prepare("UPDATE admins SET status='active',two_factor_secret='DO_NOT_EXPOSE_2FA' WHERE id=?")->execute([$aid]);
check(http('POST','/admin/auth/login',$login)[0]===403,'existing two-factor requirement is never bypassed');
$db->prepare('UPDATE admins SET two_factor_secret=NULL WHERE id=?')->execute([$aid]);
foreach(['page=0','page[]=1','per_page=101','status[]=failed','status=unknown','action=terminate','invoice_id=1+OR+1%3D1','instance_id=-1'] as $q){check(http('GET','/admin/tasks?'.$q,null,$admin)[0]===400,'reject invalid filter: '.$q);}
[$status,$rows]=http('GET','/admin/tasks?status=manual&per_page=1&instance_id='.$first['instance_id'],null,$admin);
check($status===200 && $rows['data']['total']===1 && count($rows['data']['items'])===1,'paged manual queue supports instance filter');
check(http('GET','/admin/tasks?status=manual&instance_id='.$first['instance_id'].'&page=2&per_page=1',null,$admin)[1]['data']['items']===[],'page beyond matching records is empty');
check(http('GET','/admin/tasks?invoice_id='.$second['invoice_id'],null,$admin)[1]['data']['items'][0]['id']===$second['task_id'],'invoice filter locates original task');
check(http('GET','/admin/tasks/999999999',null,$admin)[0]===404,'missing task returns 404');
[$status,$response]=http('GET','/admin/tasks/'.$first['task_id'],null,$admin);$d=$response['data'];
check($status===200 && $d['invoice']['status']==='paid' && $d['instance']['id']===$first['instance_id'] && $d['user']['id']===$uid,'detail correlates paid invoice, original instance and owner');
check($d['task']['upstream_task_id']==='host:fixture' && in_array('fulfill',$d['allowed_actions'],true),'known upstream reference and allowed actions visible');
check(!str_contains(json_encode($response),'DO_NOT_EXPOSE') && !str_contains(json_encode($response),'encrypted_password') && !str_contains(json_encode($response),'config_snapshot'),'task detail omits raw payload, config and credentials');
check(http('POST',$path,array_replace($body,['evidence'=>str_repeat(' ',20)]),$admin)[0]===400,'blank evidence rejected');
check(http('POST',$path,array_replace($body,['ip_address'=>['bad']]),$admin)[0]===400,'malformed IP returns validation failure without state change');
check(http('POST',$path,array_replace($body,['expected_execution_token'=>6]),$admin)[0]===409,'stale execution token rejected');
check(http('POST',$path,['action'=>'retry','evidence'=>$body['evidence'],'expected_execution_token'=>7],$admin)[0]===400,'retry requires explicit proof of nonexecution');
check((int)avalue("SELECT COUNT(*) FROM admin_audit_logs WHERE target_type='task' AND target_id=?",[$first['task_id']])===0,'rejected attempts create no successful audit record');
// Another blocker must disable UI candidates and reject writes while preserving the original token.
$db->prepare("INSERT INTO tasks(instance_id,invoice_id,idempotency_key,action,status) VALUES(?,?,?,'create','investigating')")->execute([$first['instance_id'],$first['invoice_id'],'admin-conflict']);$other=(int)$db->lastInsertId();
check(http('GET','/admin/tasks/'.$first['task_id'],null,$admin)[1]['data']['allowed_actions']===[],'conflicting operation suppresses resolution candidates');
check(http('POST',$path,$body,$admin)[0]===409,'conflicting operation blocks direct resolution');
$db->prepare("UPDATE tasks SET status='success' WHERE id=?")->execute([$other]);
// Two simultaneous administrators submit the exact page token; only one may commit.
$multi=curl_multi_init();$handles=[];
for($n=0;$n<2;$n++){$h=curl_init(getenv('TEST_BASE_URL').'/api/v1'.$path);curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($body),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$admin],CURLOPT_TIMEOUT=>15]);curl_multi_add_handle($multi,$h);$handles[]=$h;}
do{curl_multi_exec($multi,$running);if($running){curl_multi_select($multi,1);}}while($running);
$statuses=[];foreach($handles as $h){$statuses[]=curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_multi_remove_handle($multi,$h);curl_close($h);}curl_multi_close($multi);sort($statuses);
check($statuses===[200,409],'concurrent identical resolution commits once and rejects stale competitor');
check(http('POST',$path,$body,$admin)[0]===409,'completed resolution replay rejected');
$d=http('GET','/admin/tasks/'.$first['task_id'],null,$admin)[1]['data'];
check($d['task']['status']==='success' && $d['task']['execution_token']===8 && $d['instance']['remote_instance_id']==='admin:asset-one','fulfillment fences old token and binds original resource');
check($d['audit']['total']===1 && $d['audit']['items'][0]['context']['evidence']===$body['evidence'] && $d['allowed_actions']===[],'successful evidence and operator visible with no repeat actions');
check(http('GET','/admin/tasks/'.$first['task_id'].'?audit_page=2',null,$admin)[1]['data']['audit']['items']===[],'audit pagination does not repeat records');
// Read-only projection sanitizes legacy raw error text.
$db->prepare("UPDATE tasks SET last_error='password=DO_NOT_EXPOSE_ERROR' WHERE id=?")->execute([$second['task_id']]);
$d=http('GET','/admin/tasks/'.$second['task_id'],null,$admin)[1]['data'];check($d['task']['last_error']==='DETAILS_REDACTED','legacy raw upstream errors are redacted');
$db->prepare("UPDATE tasks SET last_error='MANUAL_AUDIT_REQUIRED:NON_QUERYABLE' WHERE id=?")->execute([$second['task_id']]);
// Existing safe retry and explicit wallet compensation are reused without any gateway refund request.
$b=['action'=>'retry','evidence'=>'OPS-102: upstream proves no execution, debit or pending request','expected_execution_token'=>7,'confirmed_no_execution_or_pending_request'=>true];
check(http('POST','/admin/tasks/'.$second['task_id'].'/resolve',$b,$admin)[0]===200 && avalue('SELECT status FROM tasks WHERE id=?',[$second['task_id']])==='pending','verified retry returns original task to worker with fencing');
$third=manual();$before=avalue('SELECT balance FROM users WHERE id=?',[$uid]);$b['action']='cancel_compensate';
check(http('POST','/admin/tasks/'.$third['task_id'].'/resolve',$b,$admin)[0]===200,'explicit permanent cancellation reuses existing wallet-only compensation');
check(http('POST','/admin/tasks/'.$third['task_id'].'/resolve',$b,$admin)[0]===409 && avalue('SELECT balance FROM users WHERE id=?',[$uid])===bcadd($before,'10.00',2),'wallet compensation is not duplicated by replay');
check(http('GET','/admin/tasks?task_id='.$third['task_id'],null,$admin)[1]['data']['total']===0,'default attention excludes permanently cancelled compensated tasks');
check(http('GET','/admin/tasks?status=all&task_id='.$third['task_id'],null,$admin)[1]['data']['total']===1,'closed task history remains queryable through explicit all filter');
// Read-only renewal investigation does not change funds, local due date, or original resource.
$db->prepare("INSERT INTO invoices(user_id,instance_id,type,total,status) VALUES(?,?,'renew',10,'paid')")->execute([$uid,$first['instance_id']]);$renewInvoice=(int)$db->lastInsertId();
$db->prepare("INSERT INTO tasks(instance_id,invoice_id,idempotency_key,action,status,execution_token,last_error,upstream_task_id) VALUES(?,?,?,'renew','failed',7,'MANUAL_AUDIT_REQUIRED:TIMEOUT','invoice:original')")->execute([$first['instance_id'],$renewInvoice,'admin-renew']);$renew=(int)$db->lastInsertId();
$due=avalue('SELECT due_date FROM instances WHERE id=?',[$first['instance_id']]);$balance=avalue('SELECT balance FROM users WHERE id=?',[$uid]);
$d=http('GET','/admin/tasks/'.$renew,null,$admin)[1]['data'];check($d['allowed_actions']===['investigate','fulfill','retry'],'renewal offers investigation and fulfillment without cancellation');
check(http('POST','/admin/tasks/'.$renew.'/resolve',['action'=>'fulfill','evidence'=>$body['evidence'],'expected_execution_token'=>7,'remote_instance_id'=>'another:asset'],$admin)[0]===409,'renewal cannot substitute another resource');
$b['action']='investigate';
check(http('POST','/admin/tasks/'.$renew.'/resolve',$b,$admin)[0]===200 && avalue('SELECT status FROM tasks WHERE id=?',[$renew])==='investigating','admin can restart only the existing read-only renewal investigation');
check(avalue('SELECT balance FROM users WHERE id=?',[$uid])===$balance && avalue('SELECT due_date FROM instances WHERE id=?',[$first['instance_id']])===$due && avalue('SELECT upstream_task_id FROM tasks WHERE id=?',[$renew])==='invoice:original','investigation retains money, local period and upstream operation identity');
// Production path uses Redis-backed rate limiting even when captcha is configured off.
for($n=0;$n<10;$n++){RateLimiter::hit($rateKey,60);}
check(http('POST','/admin/auth/login',$login)[0]===429,'admin password endpoint is rate limited');RateLimiter::clear($rateKey);
// Later suites exercise a global Worker scan. Retire only this suite's synthetic queued work.
$db->prepare("UPDATE tasks t JOIN instances i ON i.id=t.instance_id SET t.status='failed',t.last_error='PERMANENTLY_CANCELLED:ISOLATED_TEST_FINISHED',t.execution_token=t.execution_token+1,t.worker_id=NULL,t.lease_until=NULL WHERE i.user_id=? AND t.status IN ('pending','running','investigating')")->execute([$uid]);
echo "Admin checks: {$checks}\n";
