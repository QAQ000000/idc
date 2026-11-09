<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
set_exception_handler(static function (Throwable $e): never { fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n"); exit(1); });
use App\Core\{Database, ApiException, Security};
use App\Plugins\Providers\{ProviderRegistry, UpstreamDriverInterface, PreflightResult, DriverExecutionResult as R};
use App\Services\{BillingService, DemoService, OutboxWorker, ProviderService, TaskResolutionService, PaymentService};
if (getenv('APP_ENV') !== 'test' || getenv('DB_DATABASE') !== 'jianyun_test') { throw new RuntimeException('Isolated DB required'); }
$db = Database::getInstance(); $checks = 0;
function ok(bool $v, string $msg): void { global $checks; if (!$v) { throw new RuntimeException('FAIL: ' . $msg); } $checks++; echo "PASS M2: $msg\n"; }
function m2value(string $sql, array $args = []): mixed { global $db; $s=$db->prepare($sql);$s->execute($args);return $s->fetchColumn(); }
function conflict(callable $f, string $msg): void { try { $f(); } catch (ApiException $e) { ok($e->httpStatus === 409 || $e->httpStatus === 403, $msg);return; } throw new RuntimeException('Expected rejection: '.$msg); }
$db->prepare('INSERT INTO users (email,password,balance) VALUES (?,?,1000)')->execute(['m2@example.test',Security::hashPassword('M2TestPassword!22')]); $user=(int)$db->lastInsertId();
$product=(new DemoService())->seed();
$db->prepare('UPDATE products SET is_auto_setup=1 WHERE id=?')->execute([$product]);
function purchase(bool $paid = true): array { global $product,$user; return (new BillingService())->checkout($user,['product_id'=>$product,'hostname'=>'m2-host','password'=>'M2RootPassword!22','billing_cycle'=>'monthly','use_balance'=>$paid]); }
function taskFor(array $invoice): array { global $db; $s=$db->prepare('SELECT * FROM tasks WHERE invoice_id=?');$s->execute([$invoice['invoice_id']]);return $s->fetch(); }
// Isolate worker scanning from M0's deliberately unfinished scenarios.
$db->exec("UPDATE tasks SET status='success',lease_until=NULL WHERE status IN ('pending','running','investigating')");
$driver = new class implements UpstreamDriverInterface {
 public int $creates=0;public int $queries=0;public string $authority='EVENTUAL_CONSISTENCY';public ?R $next=null;
 public function getDriverId(): string {return 'manual';} public function getQueryAuthority(): string {return $this->authority;}
 public function getFormSchema(int $p,array $c):array{return [];}
 public function preflightPrice(int $p,string $b,array $o,array $c):PreflightResult{return new PreflightResult('10.00',[]);}
 public function create(array $o,array $c):R {$this->creates++;return $this->next??new R('unknown',code:'TIMEOUT');}
 public function queryOrder(array $o,array $c):R {$this->queries++;return $this->next??new R('unknown',code:'NOT_YET_VISIBLE');}
};
$worker=new OutboxWorker(new ProviderService(new ProviderRegistry(['manual'=>static fn()=>$driver])));
$i=purchase();$t=taskFor($i);$c=$worker->claim('worker-a');ok($c && (int)$c['id']===(int)$t['id'],'claim pending task after invoice payment');
ok($worker->claim('worker-b')===null,'second worker cannot steal a live lease');
$db->prepare("UPDATE tasks SET lease_until=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id=?")->execute([$t['id']]);
conflict(fn()=>$worker->finish($c,new R('succeeded','host-old')),'expired worker cannot activate');
$c2=$worker->claim('worker-b');ok($c2['status']==='investigating' && $c2['execution_token']>$c['execution_token'],'crashed worker takeover increments token and investigates');
conflict(fn()=>$worker->finish($c,new R('succeeded','host-old')),'old token cannot overwrite takeover');
$worker->finish($c2,new R('unknown',code:'NOT_YET_VISIBLE'));
ok(m2value('SELECT status FROM tasks WHERE id=?',[$t['id']])==='investigating','negative eventual query preserves business blocker');
$db->prepare("INSERT INTO tasks(instance_id,invoice_id,idempotency_key,action,payload) VALUES(?,?,?,'create','{}')")->execute([$i['instance_id'],$i['invoice_id'],'sibling-'.$t['id']]);$sibling=(int)$db->lastInsertId();
ok($worker->claim('worker-c')===null,'investigating without lease still blocks sibling');
$db->prepare('UPDATE tasks SET next_retry_at=NULL WHERE id=?')->execute([$t['id']]);
$driver->next=new R('succeeded','cdn:test-1');$worker->runOnce('worker-c');
ok($driver->creates===0 && $driver->queries===1,'crash recovery queries without a second create');
ok(m2value('SELECT status FROM instances WHERE id=?',[$i['instance_id']])==='active' && m2value('SELECT start_date FROM instances WHERE id=?',[$i['instance_id']])!==null,'confirmed CDN delivery activates without requiring an IP');
$db->prepare("UPDATE tasks SET status='failed',last_error='PERMANENTLY_CANCELLED:TEST' WHERE id=?")->execute([$sibling]);
$i2=purchase();$driver->next=new R('unknown',code:'TIMEOUT');$worker->runOnce('worker-d');$t2=taskFor($i2);
ok($driver->creates===1 && $t2['status']==='investigating','timeout after create enters investigation');
$db->prepare('UPDATE tasks SET next_retry_at=NULL WHERE id=?')->execute([$t2['id']]);$driver->authority='NON_QUERYABLE';$worker->runOnce('worker-e');$t2=taskFor($i2);
ok(str_starts_with($t2['last_error'],'MANUAL_AUDIT_REQUIRED:') && $driver->creates===1,'unqueryable upstream stops replay and raises manual gate');
$db->prepare("INSERT INTO admins(username,email,password,role) VALUES('m2-admin','m2-admin@example.test',?,'super_admin')")->execute([Security::hashPassword('M2AdminPassword!22')]);$admin=(int)$db->lastInsertId();
$resolve=new TaskResolutionService();$input=['action'=>'cancel_compensate','evidence'=>'Ticket TEST-42: upstream confirms no asset and no delayed execution','expected_execution_token'=>(int)$t2['execution_token'],'confirmed_no_execution_or_pending_request'=>true];
conflict(fn()=>$resolve->resolve(999999,(int)$t2['id'],$input),'manual resolution requires active super admin');
$before=m2value('SELECT balance FROM users WHERE id=?',[$user]);$resolve->resolve($admin,(int)$t2['id'],$input);
ok(bccomp(m2value('SELECT balance FROM users WHERE id=?',[$user]),bcadd($before,$i2['total'],2),2)===0,'permanent undelivered cancellation compensates actual receipt once');
ok(m2value('SELECT status FROM invoices WHERE id=?',[$i2['invoice_id']])==='refunded' && m2value('SELECT status FROM instances WHERE id=?',[$i2['instance_id']])==='terminated','compensation atomically closes invoice and instance');
conflict(fn()=>$resolve->resolve($admin,(int)$t2['id'],$input),'compensation replay is rejected');
ok((int)m2value("SELECT COUNT(*) FROM admin_audit_logs WHERE target_id=? AND action='task_cancel_compensate'",[$t2['id']])===1,'compensation audit is atomic and unique');

// Explicit manual fulfillment and verified no-execution retry have their own audited path.
$manual=purchase();$driver->next=new R('unknown',code:'NON_QUERYABLE');$worker->runOnce('manual-delivery');$mt=taskFor($manual);
$resolve->resolve($admin,(int)$mt['id'],['action'=>'fulfill','evidence'=>'Ticket TEST-43: confirmed upstream asset belongs to this order','expected_execution_token'=>(int)$mt['execution_token'],'remote_instance_id'=>'host:manual-confirmed']);
ok(m2value('SELECT status FROM instances WHERE id=?',[$manual['instance_id']])==='active' && m2value('SELECT lease_until FROM tasks WHERE id=?',[$mt['id']])===null,'manual fulfillment activates through audited resolution without forging a worker lease');
$retry=purchase();$worker->runOnce('manual-retry');$rt=taskFor($retry);
$resolve->resolve($admin,(int)$rt['id'],['action'=>'retry','evidence'=>'Ticket TEST-44: upstream confirmed original request never executed','expected_execution_token'=>(int)$rt['execution_token'],'confirmed_no_execution_or_pending_request'=>true]);
ok(m2value('SELECT status FROM tasks WHERE id=?',[$rt['id']])==='pending' && (int)m2value('SELECT execution_token FROM tasks WHERE id=?',[$rt['id']])>(int)$rt['execution_token'],'verified manual retry invalidates old execution token');
$rc=$worker->claim('verified-retry');$worker->finish($rc,new R('succeeded','host:manual-retried'));
// Real Epay signature plugin with isolated merchant settings; no live funds or callbacks.
$config=['enabled'=>true,'pid'=>'test-1000','endpoint'=>'http://127.0.0.1:82','notify_url'=>'http://127.0.0.1/notify','return_url'=>'http://127.0.0.1'];
$db->prepare("INSERT INTO system_settings(`key`,value,encrypted_credentials) VALUES('payment.epay',?,?) ON DUPLICATE KEY UPDATE value=VALUES(value),encrypted_credentials=VALUES(encrypted_credentials)")->execute([json_encode($config),Security::encrypt(json_encode(['key'=>'isolated-test-secret']))]);
function notification(string $order,string $trade,string $amount):array {$p=['pid'=>'test-1000','out_trade_no'=>$order,'trade_no'=>$trade,'money'=>$amount,'trade_status'=>'TRADE_SUCCESS','sign_type'=>'MD5'];$p['sign']=\Plugins\payments\epay\EpayDriver::sign($p,'isolated-test-secret');return $p;}
$pay=new PaymentService();$i3=purchase(false);$form=$pay->checkout($user,(int)$i3['invoice_id'],'epay')['checkout'];$merchant=$form['fields']['out_trade_no'];
ok(!str_contains(json_encode($form),'isolated-test-secret') && $form['fields']['type']==='alipay','checkout sends required payment type and signature without merchant key');
$another=$pay->checkout($user,(int)$i3['invoice_id'],'epay')['checkout'];ok($another['fields']['out_trade_no']!==$merchant,'new cashier intent receives a fresh attempt without losing the old receipt identity');
$n=notification($merchant,'gateway-1',$i3['total']);$before=m2value('SELECT balance FROM users WHERE id=?',[$user]);
$bad=$n;$bad['trade_status']='WAIT_BUYER_PAY';$bad['sign']=\Plugins\payments\epay\EpayDriver::sign($bad,'isolated-test-secret');try{$pay->notify('epay',$bad);throw new RuntimeException('nonfinal accepted');}catch(ApiException){ok(true,'signed nonfinal notification cannot settle');}
$pay->notify('epay',$n);$pay->notify('epay',$n);
ok(m2value('SELECT status FROM invoices WHERE id=?',[$i3['invoice_id']])==='paid' && (int)m2value('SELECT COUNT(*) FROM tasks WHERE invoice_id=?',[$i3['invoice_id']])===1,'duplicate payment callback settles exactly one Outbox');
ok(m2value('SELECT balance FROM users WHERE id=?',[$user])===$before,'external direct payment does not fake wallet movements');
$n2=notification($merchant,'gateway-2',$i3['total']);$pay->notify('epay',$n2);$pay->notify('epay',$n2);
ok(bccomp(m2value('SELECT balance FROM users WHERE id=?',[$user]),bcadd($before,$i3['total'],2),2)===0,'distinct extra receipt against same attempt credits wallet exactly once');
$i4=purchase(false);$form=$pay->checkout($user,(int)$i4['invoice_id'],'epay')['checkout'];(new BillingService())->cancel($user,(int)$i4['invoice_id']);
$n4=notification($form['fields']['out_trade_no'],'gateway-late',$i4['total']);$pay->notify('epay',$n4);
ok(m2value('SELECT status FROM invoices WHERE id=?',[$i4['invoice_id']])==='cancelled' && (int)m2value('SELECT COUNT(*) FROM tasks WHERE invoice_id=?',[$i4['invoice_id']])===0,'late receipt credits wallet without reviving cancelled invoice');

// HTTP contract and concurrent gateway retries, exercising real controller parameter parsing.
function rawNotify(array $params,string $method='GET'): array {
 $url=getenv('TEST_BASE_URL').'/api/v1/payments/epay/notify';
 $curl=curl_init($url.($method==='GET'?'?'.http_build_query($params):''));
 curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10]);
 if($method==='POST'){curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($params)]);}
 $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);return [$status,$body];
}
$ci=purchase(false);$cf=$pay->checkout($user,(int)$ci['invoice_id'],'epay')['checkout'];$cn=notification($cf['fields']['out_trade_no'],'concurrent-gateway',$ci['total']);
$bad=$cn;$bad['money']='0.01';ok(rawNotify($bad)===[400,'fail'],'tampered signed amount rejected through HTTP');
$multi=curl_multi_init();$curls=[];
for($x=0;$x<8;$x++){$h=curl_init(getenv('TEST_BASE_URL').'/api/v1/payments/epay/notify?'.http_build_query($cn));curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15]);$curls[]=$h;curl_multi_add_handle($multi,$h);}
do{curl_multi_exec($multi,$running);if($running){curl_multi_select($multi,1);}}while($running);
$all=true;foreach($curls as $h){$all=$all&&curl_getinfo($h,CURLINFO_RESPONSE_CODE)===200&&curl_multi_getcontent($h)==='success';curl_multi_remove_handle($multi,$h);curl_close($h);}curl_multi_close($multi);
ok($all && (int)m2value('SELECT COUNT(*) FROM tasks WHERE invoice_id=?',[$ci['invoice_id']])===1,'eight concurrent GET callbacks each acknowledge success and create one task');
ok(rawNotify($cn,'POST')===[200,'success'],'POST callback has same literal acknowledgement and deduplication');
$blocked=purchase(false);$bf=$pay->checkout($user,(int)$blocked['invoice_id'],'epay')['checkout'];
$db->prepare("INSERT INTO tasks(instance_id,invoice_id,idempotency_key,action,status,last_error) VALUES(?,?,?,'create','failed','MANUAL_AUDIT_REQUIRED:TEST')")->execute([$blocked['instance_id'],$blocked['invoice_id'],'blocked:'.$blocked['invoice_id']]);
$bn=notification($bf['fields']['out_trade_no'],'blocked-real-receipt',$blocked['total']);
$before=m2value('SELECT balance FROM users WHERE id=?',[$user]);$pay->notify('epay',$bn);
ok(m2value('SELECT status FROM invoices WHERE id=?',[$blocked['invoice_id']])==='unpaid' && bccomp(m2value('SELECT balance FROM users WHERE id=?',[$user]),bcadd($before,$blocked['total'],2),2)===0,'unresolved task routes real funds to wallet without settling original invoice');
conflict(fn()=>(new BillingService())->pay($user,(int)$blocked['invoice_id']),'balance payment respects unresolved task gate without a debit');
// A signed wrong amount is still real attributable money; credit it exactly once.
$mismatch=purchase(false);$mf=$pay->checkout($user,(int)$mismatch['invoice_id'],'epay')['checkout'];$mn=notification($mf['fields']['out_trade_no'],'wrong-real-amount','0.01');$pay->notify('epay',$mn);$pay->notify('epay',$mn);
ok(m2value('SELECT status FROM invoices WHERE id=?',[$mismatch['invoice_id']])==='unpaid' && (int)m2value("SELECT COUNT(*) FROM payment_records WHERE invoice_id=? AND status='late_credited_to_balance'",[$mismatch['invoice_id']])===1,'signed mismatched receipt is retained and credited without delivery');
$curl=curl_init(getenv('TEST_BASE_URL').'/api/v1/instances/'.$i['instance_id'].'/credentials');curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.Security::generateToken(['uid'=>$user])]]);curl_exec($curl);ok(curl_getinfo($curl,CURLINFO_RESPONSE_CODE)===200,'owned credential endpoint is reachable over HTTP');curl_close($curl);
$db->prepare("UPDATE tasks SET status='success' WHERE invoice_id=?")->execute([$ci['invoice_id']]);
// Current-read refund gate and retry/backoff.
$db->prepare("UPDATE tasks SET status='success' WHERE invoice_id=?")->execute([$i3['invoice_id']]);
$i5=purchase();$t5=taskFor($i5);$db->prepare("UPDATE invoices SET status='refund_pending' WHERE id=?")->execute([$i5['invoice_id']]);ok($worker->claim('refund-worker')===null,'refund pending blocks task admission');
$db->prepare("UPDATE invoices SET status='paid' WHERE id=?")->execute([$i5['invoice_id']]);$c5=$worker->claim('safe-retry');$worker->finish($c5,new R('not_executed',code:'NO_REQUEST_SENT'));
ok($worker->claim('too-soon')===null,'proven nonexecution retry honors backoff');


// Exercise the production driver over real HTTP, including lost responses and a SIGKILLed Worker.
$db->exec("UPDATE tasks SET status='success',lease_until=NULL WHERE status IN ('pending','running','investigating')");
$upstream=getenv('UPSTREAM_TEST_URL');
require_once __DIR__.'/reset_payment_fixture.php';resetPaymentFixture();
$setup=(new \App\Services\ConnectionSetupService())->install([
 'provider'=>['name'=>'isolated-zjmf','driver'=>'zjmf','endpoint'=>$upstream,'credentials'=>['username'=>'test@example.test','password'=>'isolated-api-key']],
 'product'=>['remote_pid'=>391,'name'=>'CDN protocol fixture','type'=>'cdn','monthly_price'=>'0.01'],
 'payment'=>['driver'=>'epay','endpoint'=>'http://127.0.0.1:82','pid'=>'fixture-new','notify_url'=>'http://127.0.0.1/notify','return_url'=>'http://127.0.0.1','credentials'=>['key'=>'new-isolated-key']]
]);

$product=$setup['product_id'];$realWorker=new OutboxWorker();
function fixture(string $path):array { global $upstream;$r=file_get_contents($upstream.$path);return json_decode($r,true,32,JSON_THROW_ON_ERROR); }
fixture('/__mode?mode=normal');$realOrder=purchase();$realWorker->runOnce('http-worker');
ok(m2value('SELECT status FROM instances WHERE id=?',[$realOrder['instance_id']])==='active','real driver HTTP protocol settles, pays and verifies Active');
$owned=new \App\Services\InstanceService();
ok(!str_contains(json_encode($owned->detail($user,(int)$realOrder['instance_id'])),'RemoteSecret'),'instance detail never exposes credentials by default');
ok($owned->credentials($user,(int)$realOrder['instance_id'])['password']==='RemoteSecret!NotInList','remote credentials are encrypted at rest and returned only through owner endpoint');
try{$owned->credentials(999999,(int)$realOrder['instance_id']);throw new RuntimeException('foreign credentials exposed');}catch(ApiException $e){ok($e->httpStatus===404,'foreign instance and credentials are hidden');}
fixture('/__mode?mode=pending');$renamed=purchase();$realWorker->runOnce('pending-rename');$renamedTask=taskFor($renamed);
ok($renamedTask['status']==='investigating' && str_starts_with($renamedTask['upstream_task_id'],'host:'),'pending remote resource retains its account scoped stable ID');
fixture('/__rename_activate');fixture('/__wrong_host_id?enabled=1');
$db->prepare('UPDATE tasks SET next_retry_at=NULL WHERE id=?')->execute([$renamedTask['id']]);$realWorker->runOnce('reject-wrong-id');
ok(m2value('SELECT status FROM instances WHERE id=?',[$renamed['instance_id']])==='pending','hostinfo cannot substitute a different ID for the recorded resource');
fixture('/__wrong_host_id?enabled=0');
$db->prepare('UPDATE tasks SET next_retry_at=NULL WHERE id=?')->execute([$renamedTask['id']]);$realWorker->runOnce('renamed-recovery');
ok(m2value('SELECT status FROM instances WHERE id=?',[$renamed['instance_id']])==='active' && fixture('/__state')['creates']===1,'renamed resource recovers by recorded ID and account without repurchase');
fixture('/__mode?mode=drop_after_create');
$lost=purchase();$realWorker->runOnce('lost-response');$lostTask=taskFor($lost);
ok($lostTask['status']==='investigating' && fixture('/__state')['creates']===1,'server creates resource then drops response; worker records unknown');
$db->prepare('UPDATE tasks SET next_retry_at=NULL WHERE id=?')->execute([$lostTask['id']]);$realWorker->runOnce('lost-response-recovery');
ok(m2value('SELECT status FROM instances WHERE id=?',[$lost['instance_id']])==='active' && fixture('/__state')['creates']===1,'lost response recovered through read-only hostname and owner scoped hostinfo without repurchase');
fixture('/__mode?mode=hold_after_create');
$killed=purchase();
$process=proc_open([PHP_BINARY, 'artisan', 'outbox:work', '--once'],[['pipe','r'],['file','/dev/null','w'],['file','/dev/null','w']],$pipes,dirname(__DIR__));
fclose($pipes[0]);$deadline=microtime(true)+8;
while(fixture('/__state')['creates']!==1){if(microtime(true)>$deadline){proc_terminate($process,9);throw new RuntimeException('worker did not reach upstream');}usleep(100000);}
proc_terminate($process,9);proc_close($process);$killedTask=taskFor($killed);
ok($killedTask['status']==='running','SIGKILL leaves durable claim after upstream created');
$db->prepare('UPDATE tasks SET lease_until=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id=?')->execute([$killedTask['id']]);
$realWorker->runOnce('killed-recovery');
ok(m2value('SELECT status FROM instances WHERE id=?',[$killed['instance_id']])==='active' && fixture('/__state')['creates']===1,'killed Worker recovered with fencing and no second upstream purchase');
echo "M2 checks: {$checks}\n";
