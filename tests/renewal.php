<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
set_exception_handler(static function(Throwable $e):never {fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
use App\Core\{Database,ApiException,Security};
use App\Services\{BillingService,RenewalService,OutboxWorker,ConnectionSetupService,PaymentService};
if(getenv('APP_ENV')!=='test'||getenv('DB_DATABASE')!=='jianyun_test'){throw new RuntimeException('Isolated DB required');}
$db=Database::getInstance();$checks=0;$billing=new BillingService();$renew=new RenewalService();$worker=new OutboxWorker();
function check(bool $v,string $msg):void {global $checks;if(!$v){throw new RuntimeException('FAIL: '.$msg);}$checks++;echo "PASS renewal: $msg\n";}
function renewalValue(string $sql,array $a=[]):mixed {global $db;$s=$db->prepare($sql);$s->execute($a);return $s->fetchColumn();}
function reject(callable $f,string $msg,int $code=409):void {try{$f();}catch(ApiException $e){check($e->httpStatus===$code,$msg);return;}throw new RuntimeException('Expected rejection: '.$msg);}
function fixtureRenew(string $p):array{return json_decode(file_get_contents(getenv('UPSTREAM_TEST_URL').$p),true,32,JSON_THROW_ON_ERROR);}
function row(string $table,int $id):array {global $db;$s=$db->prepare("SELECT * FROM $table WHERE id=?");$s->execute([$id]);return $s->fetch();}
function task(int $invoice):array {global $db;$s=$db->prepare('SELECT * FROM tasks WHERE invoice_id=?');$s->execute([$invoice]);return $s->fetch();}
function runTask(int $invoice):void {global $db,$worker;$t=task($invoice);$db->prepare('UPDATE tasks SET next_retry_at=NULL WHERE id=?')->execute([$t['id']]);$worker->runOnce('renewal-test');}
function apiRenew(string $path,array $body,int $uid,string $method='POST'):array {
 $c=curl_init(getenv('TEST_BASE_URL').'/api/v1'.$path);curl_setopt_array($c,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_POSTFIELDS=>json_encode($body),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.Security::generateToken(['uid'=>$uid])],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15]);$r=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);return [$status,json_decode($r,true)];
}
fixtureRenew('/__mode?mode=normal');
$db->exec("UPDATE tasks SET status='success',lease_until=NULL WHERE action IN ('create','renew') AND status IN ('pending','running','investigating')");
$db->prepare('INSERT INTO users(email,password,balance) VALUES(?,?,100)')->execute(['renewal@example.test',Security::hashPassword('RenewalFixture!2026')]);$user=(int)$db->lastInsertId();
require_once __DIR__.'/reset_payment_fixture.php';resetPaymentFixture();
$setup=(new ConnectionSetupService())->install([
 'provider'=>['name'=>'renewal-fixture','driver'=>'zjmf','endpoint'=>getenv('UPSTREAM_TEST_URL'),'credentials'=>['username'=>'fixture@example.test','password'=>'fixture-key']],
 'product'=>['remote_pid'=>391,'name'=>'CDN renewal fixture','type'=>'cdn','monthly_price'=>'0.01'],
 'payment'=>['driver'=>'epay','endpoint'=>'http://127.0.0.1:82','pid'=>'renew-fixture','notify_url'=>'http://127.0.0.1/notify','return_url'=>'http://127.0.0.1','credentials'=>['key'=>'renew-fixture-secret']]
]);$product=$setup['product_id'];
function resource(string $due='2027-01-31 12:34:56'):array {
 global $billing,$worker,$user,$product,$db;
 $v=$billing->checkout($user,['product_id'=>$product,'hostname'=>'renew-fixture','password'=>'RenewalFixture!2026','billing_cycle'=>'monthly','use_balance'=>true]);
 $worker->runOnce('renewal-create');
 $db->prepare('UPDATE instances SET due_date=? WHERE id=?')->execute([$due,$v['instance_id']]);
 return $v;
}
function notice(int $invoice,string $trade):array {
 global $user;$form=(new PaymentService())->checkout($user,$invoice,'epay')['checkout'];
 $n=['pid'=>'renew-fixture','out_trade_no'=>$form['fields']['out_trade_no'],'trade_no'=>$trade,'money'=>$form['fields']['money'],'trade_status'=>'TRADE_SUCCESS','sign_type'=>'MD5'];
 $n['sign']=\Plugins\payments\epay\EpayDriver::sign($n,'renew-fixture-secret');return $n;
}
$first=resource();$iid=(int)$first['instance_id'];$base=row('instances',$iid)['due_date'];
[$status,$response]=apiRenew('/instances/'.$iid.'/renew',['base_due_date'=>$base],$user);
check($status===200 && $response['data']['type']==='renew','owner creates renewal invoice over HTTP');$v=$response['data'];$vid=(int)$v['invoice_id'];
[$invalidStatus]=apiRenew('/instances/'.$iid.'/renew',['base_due_date'=>'2027-99-99 99:99:99'],$user);check($invalidStatus===400,'invalid renewal date is rejected as client input');
check($v['quote_expired_at']===null && $v['total']==='0.01','renewal uses fixed recurring price with no 30 minute expiry');
check($renew->issue($user,$iid,$base)['invoice_id']===$v['invoice_id'],'same original period returns its unique invoice');
reject(fn()=>$renew->issue(999999,$iid,$base),'foreign renewal is hidden',404);
$before=renewalValue('SELECT balance FROM users WHERE id=?',[$user]);$billing->pay($user,$vid);$billing->pay($user,$vid);
check(row('instances',$iid)['due_date']==='2027-02-28 12:34:56','monthly renewal clamps month end from original expiry, not payment time');
check(bccomp(renewalValue('SELECT balance FROM users WHERE id=?',[$user]),bcsub($before,'0.01',2),2)===0 && (int)renewalValue('SELECT COUNT(*) FROM tasks WHERE invoice_id=?',[$vid])===1,'duplicate balance payment debits and enqueues once');
runTask($vid);check(task($vid)['status']==='success' && fixtureRenew('/__state')['renew_payments']===1,'renew Outbox confirms upstream expiry before completion');
[$status,$response]=apiRenew('/instances/'.$iid.'/renew',['base_due_date'=>$base,'use_balance'=>true],$user);
check($status===200 && (int)$response['data']['invoice_id']===$vid && fixtureRenew('/__state')['renew_payments']===1,'replayed HTTP request cannot buy the next period after delivery');

// Unique issuance under concurrent HTTP requests.
$parallel=resource();$pi=(int)$parallel['instance_id'];$multi=curl_multi_init();$handles=[];
for($x=0;$x<8;$x++){$c=curl_init(getenv('TEST_BASE_URL').'/api/v1/instances/'.$pi.'/renew');curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['base_due_date'=>'2027-01-31 12:34:56']),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.Security::generateToken(['uid'=>$user])],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20]);$handles[]=$c;curl_multi_add_handle($multi,$c);}
do{curl_multi_exec($multi,$running);if($running){curl_multi_select($multi,1);}}while($running);
$ids=[];foreach($handles as $c){check(curl_getinfo($c,CURLINFO_RESPONSE_CODE)===200,'concurrent renewal request succeeds');$ids[]=json_decode(curl_multi_getcontent($c),true)['data']['invoice_id'];curl_multi_remove_handle($multi,$c);curl_close($c);}curl_multi_close($multi);
check(count(array_unique($ids))===1 && (int)renewalValue("SELECT COUNT(*) FROM invoices WHERE instance_id=? AND type='renew'",[$pi])===1,'concurrent generation creates a single period invoice');
$pv=$renew->issue($user,$pi);$billing->cancel($user,(int)$pv['invoice_id']);
check(row('instances',$pi)['status']==='active' && renewalValue('SELECT cycle_marker FROM invoices WHERE id=?',[$pv['invoice_id']])===null,'cancelling unpaid renewal frees marker and preserves resource');
$pv=$renew->issue($user,$pi);$pn=notice((int)$pv['invoice_id'],'renew-gateway');$pay=new PaymentService();$pay->notify('epay',$pn);$pay->notify('epay',$pn);
check((int)renewalValue('SELECT COUNT(*) FROM tasks WHERE invoice_id=?',[$pv['invoice_id']])===1 && row('instances',$pi)['due_date']==='2027-02-28 12:34:56','duplicate gateway callback creates one renewal and one period extension');runTask((int)$pv['invoice_id']);

$annual=resource('2028-02-29 10:00:00');$ai=(int)$annual['instance_id'];$db->prepare("UPDATE instances SET billing_cycle='annually' WHERE id=?")->execute([$ai]);
fixtureRenew('/__cycle?hostid='.substr(row('instances',$ai)['remote_instance_id'],5).'&cycle=annually');
$av=$renew->issue($user,$ai);$billing->pay($user,(int)$av['invoice_id']);runTask((int)$av['invoice_id']);
check(row('instances',$ai)['due_date']==='2029-02-28 10:00:00' && task((int)$av['invoice_id'])['status']==='success','annual leap-day renewal delivers while preserving a clamped original billing year');

// Transaction rollback cannot leave an extended entitlement, debit or orphaned task.
$rollback=resource();$rv=$renew->issue($user,(int)$rollback['instance_id']);$before=renewalValue('SELECT balance FROM users WHERE id=?',[$user]);
$db->exec("CREATE TRIGGER renewal_outbox_failure BEFORE INSERT ON tasks FOR EACH ROW BEGIN IF NEW.action='renew' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated renewal fault'; END IF; END");
try{$billing->pay($user,(int)$rv['invoice_id']);throw new RuntimeException('Fault did not fire');}catch(PDOException){}finally{$db->exec('DROP TRIGGER renewal_outbox_failure');}
check(row('invoices',(int)$rv['invoice_id'])['status']==='unpaid' && row('instances',(int)$rollback['instance_id'])['due_date']==='2027-01-31 12:34:56' && renewalValue('SELECT balance FROM users WHERE id=?',[$user])===$before,'Outbox insertion failure rolls back debit, invoice and expiry');
$billing->cancel($user,(int)$rv['invoice_id']);

// Response lost after upstream credit payment. Recovery is strictly read-only.
$lost=resource();$lv=$renew->issue($user,(int)$lost['instance_id']);$billing->pay($user,(int)$lv['invoice_id']);fixtureRenew('/__renew_mode?mode=drop_payment');$prior=fixtureRenew('/__state');runTask((int)$lv['invoice_id']);
check(task((int)$lv['invoice_id'])['status']==='investigating','renewal payment response loss enters investigation');runTask((int)$lv['invoice_id']);$after=fixtureRenew('/__state');
check(task((int)$lv['invoice_id'])['status']==='success' && $after['renew_calls']===$prior['renew_calls']+1 && $after['renew_payments']===$prior['renew_payments']+1,'read-only expiry investigation recovers without a second renewal or payment');fixtureRenew('/__renew_mode?mode=normal');

// Billing suspension stays suspended until the upstream has actually restored service.
$suspended=resource();$si=(int)$suspended['instance_id'];$remote=substr(row('instances',$si)['remote_instance_id'],5);fixtureRenew('/__suspend?hostid='.$remote);
$db->prepare("UPDATE instances SET status='suspended',suspend_type='billing',suspend_reason='unpaid' WHERE id=?")->execute([$si]);
$sv=$renew->issue($user,$si);$billing->pay($user,(int)$sv['invoice_id']);
check(row('instances',$si)['status']==='suspended' && json_decode(task((int)$sv['invoice_id'])['payload'],true)['needs_unsuspend']===true,'billing payment extends entitlement but does not restore service locally');
fixtureRenew('/__renew_mode?mode=hold_restore');runTask((int)$sv['invoice_id']);
check(row('instances',$si)['status']==='suspended' && task((int)$sv['invoice_id'])['status']==='investigating','unconfirmed upstream restoration preserves suspension');
fixtureRenew('/__rename_activate');runTask((int)$sv['invoice_id']);
check(row('instances',$si)['status']==='active' && task((int)$sv['invoice_id'])['status']==='success','only confirmed upstream restoration clears billing suspension');fixtureRenew('/__renew_mode?mode=normal');

foreach(['compliance','abuse','manual'] as $kind){
 $restricted=resource();$ri=(int)$restricted['instance_id'];$iv=$renew->issue($user,$ri);$n=notice((int)$iv['invoice_id'],'restricted-'.$kind);
 $db->prepare("UPDATE instances SET status='suspended',suspend_type=? WHERE id=?")->execute([$kind,$ri]);
 $before=renewalValue('SELECT balance FROM users WHERE id=?',[$user]);reject(fn()=>$billing->pay($user,(int)$iv['invoice_id']),$kind.' suspension blocks balance payment');
 $pay->notify('epay',$n);$pay->notify('epay',$n);
 check(row('instances',$ri)['status']==='suspended' && row('instances',$ri)['due_date']==='2027-01-31 12:34:56' && row('invoices',(int)$iv['invoice_id'])['status']==='unpaid' && bccomp(renewalValue('SELECT balance FROM users WHERE id=?',[$user]),bcadd($before,'0.01',2),2)===0 && (int)renewalValue('SELECT COUNT(*) FROM tasks WHERE invoice_id=?',[$iv['invoice_id']])===0,$kind.' gateway receipt credits wallet once without unsuspension or renewal');
}

// Refund freeze and queued/claimed termination use the same instance lock as settlement.
$frozen=resource();$fi=(int)$frozen['instance_id'];$fv=$renew->issue($user,$fi);$db->prepare("UPDATE invoices SET status='refund_pending' WHERE id=?")->execute([$frozen['invoice_id']]);
reject(fn()=>$billing->pay($user,(int)$fv['invoice_id']),'another cloud invoice refund freezes renewal');
$db->prepare("UPDATE invoices SET status='paid' WHERE id=?")->execute([$frozen['invoice_id']]);$billing->cancel($user,(int)$fv['invoice_id']);
foreach(['billing_overdue','user_voluntary','running'] as $mode){
 $d=resource();$di=(int)$d['instance_id'];$dv=$renew->issue($user,$di);$n=notice((int)$dv['invoice_id'],'termination-'.$mode);
 $db->prepare("INSERT INTO tasks(instance_id,invoice_id,idempotency_key,action,status,payload,lease_until) VALUES(?,?,?,'terminate',?,?,DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE))")->execute([$di,$d['invoice_id'],'renew-terminate-'.$di,$mode==='running'?'running':'pending',json_encode(['termination_reason'=>$mode==='user_voluntary'?'user_voluntary':'billing_overdue'])]);$tid=(int)$db->lastInsertId();
 if($mode==='billing_overdue'){$billing->pay($user,(int)$dv['invoice_id']);check(row('tasks',$tid)['last_error']==='CANCELLED_BY_RENEWAL','pending overdue destruction is cancelled atomically by renewal');runTask((int)$dv['invoice_id']);}
 else{$before=renewalValue('SELECT balance FROM users WHERE id=?',[$user]);reject(fn()=>$billing->pay($user,(int)$dv['invoice_id']),$mode.' destruction blocks wallet debit');$pay->notify('epay',$n);$pay->notify('epay',$n);check(row('invoices',(int)$dv['invoice_id'])['status']==='refunded' && row('invoices',(int)$dv['invoice_id'])['cycle_marker']===null && row('instances',$di)['due_date']==='2027-01-31 12:34:56' && bccomp(renewalValue('SELECT balance FROM users WHERE id=?',[$user]),bcadd($before,'0.01',2),2)===0,$mode.' destruction returns actual channel receipt to wallet once without extending entitlement');}
}

// Generate and debit are independent. A recharge need not create a second invoice to make progress.
$scheduled=resource(gmdate('Y-m-d H:i:s',time()+2*86400));$sci=(int)$scheduled['instance_id'];
$db->prepare('UPDATE users SET balance=0 WHERE id=?')->execute([$user]);
$renew->generateDue();$iv=(int)renewalValue("SELECT id FROM invoices WHERE instance_id=? AND type='renew' AND status='unpaid'",[$sci]);$renew->generateDue();
check($iv>0 && (int)renewalValue("SELECT COUNT(*) FROM invoices WHERE instance_id=? AND type='renew'",[$sci])===1 && row('invoices',$iv)['status']==='unpaid','early billing is unique and does not debit');
check($renew->debitDue()===0 && row('invoices',$iv)['status']==='unpaid','insufficient balance leaves invoice waiting');
// Isolated fixture recharge uses the existing guarded demo ledger, not a production funding path.
(new \App\Services\DemoService())->recharge($user,'1.00','renewal-recharge');$renew->setAutomatic($user,$sci,false);
check($renew->debitDue()===0 && row('invoices',$iv)['status']==='unpaid','automatic opt-out blocks debit even with sufficient funds');
$renew->setAutomatic($user,$sci,true);check($renew->debitDue()===1 && $renew->debitDue()===0 && row('invoices',$iv)['status']==='paid','later recharge allows existing invoice debit exactly once');runTask($iv);
[$status,$response]=apiRenew('/instances/'.$sci.'/auto-renew',['enabled'=>false],$user,'PATCH');check($status===200 && !(bool)row('instances',$sci)['auto_renew'],'owner can disable automatic renewal over HTTP');
reject(fn()=>$renew->setAutomatic(999999,$sci,true),'foreign auto-renew changes are hidden',404);

// A killed renewal Worker may have already paid. An expired lease never authorizes replay.
$crash=resource();$cv=$renew->issue($user,(int)$crash['instance_id']);$billing->pay($user,(int)$cv['invoice_id']);fixtureRenew('/__renew_mode?mode=hold_payment');$prior=fixtureRenew('/__state');
$proc=proc_open([PHP_BINARY,'artisan','outbox:work','--once'],[['pipe','r'],['file','/dev/null','w'],['file','/dev/null','w']],$pipes,dirname(__DIR__));fclose($pipes[0]);$deadline=microtime(true)+8;
while(fixtureRenew('/__state')['renew_payments']!==$prior['renew_payments']+1){if(microtime(true)>$deadline){proc_terminate($proc,9);throw new RuntimeException('Renewal worker never paid');}usleep(100000);}
proc_terminate($proc,9);proc_close($proc);$ct=task((int)$cv['invoice_id']);
$db->prepare('UPDATE tasks SET lease_until=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id=?')->execute([$ct['id']]);runTask((int)$cv['invoice_id']);$after=fixtureRenew('/__state');
check(task((int)$cv['invoice_id'])['status']==='success' && $after['renew_calls']===$prior['renew_calls']+1 && $after['renew_payments']===$prior['renew_payments']+1,'SIGKILL recovery confirms renewed expiry without repeat purchase or debit');
fixtureRenew('/__renew_mode?mode=normal');

// Losing the submission response proves neither failure nor payment; retain the original claim.
$unknown=resource();$uv=$renew->issue($user,(int)$unknown['instance_id']);$billing->pay($user,(int)$uv['invoice_id']);fixtureRenew('/__renew_mode?mode=drop_submit');$prior=fixtureRenew('/__state');runTask((int)$uv['invoice_id']);runTask((int)$uv['invoice_id']);$ut=task((int)$uv['invoice_id']);$after=fixtureRenew('/__state');
check($ut['status']==='investigating' && $after['renew_calls']===$prior['renew_calls']+1 && $after['renew_payments']===$prior['renew_payments'],'lost renewal invoice response never repeats submission or payment');
$db->prepare("UPDATE tasks SET payload=JSON_SET(payload,'$.investigation_started_at',?) WHERE id=?")->execute([time()-3601,$ut['id']]);runTask((int)$uv['invoice_id']);$ut=task((int)$uv['invoice_id']);
check($ut['status']==='failed' && str_starts_with($ut['last_error'],'MANUAL_AUDIT_REQUIRED:'),'unresolved renewal reaches durable manual review without discarding entitlement');
$admin=(int)renewalValue("SELECT id FROM admins WHERE username='m2-admin'");$resolution=new \App\Services\TaskResolutionService();
$input=['expected_execution_token'=>(int)$ut['execution_token'],'evidence'=>'TEST-RENEW: isolated upstream invoice reviewed and no execution/debit/in-flight request remains'];
reject(fn()=>$resolution->resolve($admin,(int)$ut['id'],$input+['action'=>'cancel_compensate','confirmed_no_execution_or_pending_request'=>true]),'new-purchase compensation cannot terminate an existing renewal resource');
reject(fn()=>$resolution->resolve($admin,(int)$ut['id'],$input+['action'=>'fulfill','remote_instance_id'=>'host:another-resource']),'manual renewal cannot swap resource ownership');
reject(fn()=>$resolution->resolve($admin,(int)$ut['id'],$input+['action'=>'retry']),'manual renewal retry requires explicit nonexecution confirmation',400);
$resolution->resolve($admin,(int)$ut['id'],$input+['action'=>'retry','confirmed_no_execution_or_pending_request'=>true]);fixtureRenew('/__renew_mode?mode=normal');runTask((int)$uv['invoice_id']);
check(task((int)$uv['invoice_id'])['status']==='success' && row('instances',(int)$unknown['instance_id'])['status']==='active','audited confirmed nonexecution can retry renewal without terminating original asset');
// Paid renewal exceptions notify operators; they never trigger a renewal refund or resource cancellation.
$handler=new \Monolog\Handler\TestHandler();$logger=\Illuminate\Support\Facades\Log::channel()->getLogger();$logger->pushHandler($handler);
$alerted=resource();$alertIid=(int)$alerted['instance_id'];$alertInvoice=$renew->issue($user,$alertIid);$alertVid=(int)$alertInvoice['invoice_id'];$billing->pay($user,$alertVid);
$paidInstance=row('instances',$alertIid);$paidInvoice=row('invoices',$alertVid);$paidBalance=renewalValue('SELECT balance FROM users WHERE id=?',[$user]);$paidSpent=renewalValue('SELECT total_spent FROM users WHERE id=?',[$user]);
fixtureRenew('/__renew_mode?mode=drop_submit');$handler->clear();runTask($alertVid);$alertTask=task($alertVid);$records=$handler->getRecords();
check(count($records)===1 && $records[0]->level===\Monolog\Level::Warning && $records[0]->context['action']==='renew'
 && $records[0]->context['invoice_id']===$alertVid && $records[0]->context['instance_id']===$alertIid && $records[0]->context['task_id']===(int)$alertTask['id'],'first unconfirmed paid renewal logs an actionable operator warning');
check(row('invoices',$alertVid)===$paidInvoice && row('instances',$alertIid)===$paidInstance && renewalValue('SELECT balance FROM users WHERE id=?',[$user])===$paidBalance && renewalValue('SELECT total_spent FROM users WHERE id=?',[$user])===$paidSpent,'renewal warning preserves paid invoice, money, consumption and local entitlement');
$prior=fixtureRenew('/__state');runTask($alertVid);check(count($handler->getRecords())===1 && fixtureRenew('/__state')===$prior,'read-only unknown investigation neither repeats warning nor submits renewal or payment');
$db->prepare("UPDATE tasks SET payload=JSON_SET(payload,'$.investigation_started_at',?) WHERE id=?")->execute([time()-3601,$alertTask['id']]);
$db->exec("CREATE TRIGGER renewal_alert_fault BEFORE UPDATE ON tasks FOR EACH ROW BEGIN IF NEW.action='renew' AND NEW.status='failed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated alert commit fault'; END IF; END");
try{runTask($alertVid);}finally{$db->exec('DROP TRIGGER renewal_alert_fault');}
$records=$handler->getRecords();check(task($alertVid)['status']==='investigating' && count(array_filter($records,static fn($r)=>$r->level===\Monolog\Level::Critical))===0,'rolled-back manual transition emits no false committed manual alert');
$db->prepare('UPDATE tasks SET lease_until=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id=?')->execute([$alertTask['id']]);$handler->clear();runTask($alertVid);$alertTask=task($alertVid);$records=$handler->getRecords();
check($alertTask['status']==='failed' && str_starts_with($alertTask['last_error'],'MANUAL_AUDIT_REQUIRED:') && count($records)===1 && $records[0]->level===\Monolog\Level::Critical && $records[0]->context['invoice_id']===$alertVid,'unresolved paid renewal persists in manual list and escalates to a critical operator alert');
$listed=json_decode(shell_exec(escapeshellarg(PHP_BINARY).' artisan outbox:inspect'),true,32,JSON_THROW_ON_ERROR);$listed=array_values(array_filter($listed,static fn($t)=>(int)$t['id']===(int)$alertTask['id']));
check(count($listed)===1 && $listed[0]['action']==='renew' && (int)$listed[0]['invoice_id']===$alertVid && !array_key_exists('payload',$listed[0]),'durable inspect list identifies renewal and invoice without exposing payload or credentials');
$proof=['expected_execution_token'=>(int)$alertTask['execution_token'],'evidence'=>'TEST-ALERT: operator requests continued read-only investigation'];
reject(fn()=>$resolution->resolve(999999,(int)$alertTask['id'],$proof+['action'=>'investigate']),'only active super admin may resume manual investigation',403);
reject(fn()=>$resolution->resolve($admin,(int)$alertTask['id'],array_replace($proof,['action'=>'investigate','expected_execution_token'=>(int)$alertTask['execution_token']-1])),'manual investigation rejects stale token');
reject(fn()=>$resolution->resolve($admin,(int)$alertTask['id'],$proof+['action'=>'compensate_renewal']),'renewal refund action is not exposed',400);
$resolution->resolve($admin,(int)$alertTask['id'],$proof+['action'=>'investigate']);$resumed=task($alertVid);
check($resumed['status']==='investigating' && (int)$resumed['execution_token']===(int)$alertTask['execution_token']+1 && $resumed['upstream_task_id']===$alertTask['upstream_task_id'] && $resumed['retry_count']===$alertTask['retry_count'],'operator resumes investigation with a fresh token while preserving original remote identity and retry history');
$oldClaim=$alertTask;$oldClaim['worker_id']='renewal-test';
reject(fn()=>$worker->finish($oldClaim,new \App\Plugins\Providers\DriverExecutionResult('succeeded',row('instances',$alertIid)['remote_instance_id'])),'old worker cannot overwrite resumed operator investigation');
runTask($alertVid);$after=task($alertVid);check($after['status']==='investigating' && fixtureRenew('/__state')===$prior && count($handler->getRecords())===1,'operator investigation remains read-only without repeated warning or money movement');
check((int)renewalValue("SELECT COUNT(*) FROM transactions WHERE invoice_id=? AND type='refund_to_balance'",[$alertVid])===0 && (int)renewalValue("SELECT COUNT(*) FROM tasks WHERE instance_id=? AND action='terminate'",[$alertIid])===0 && renewalValue('SELECT refund_status FROM payment_records WHERE invoice_id=?',[$alertVid])==='none','unconfirmed renewal never refunds, terminates the original resource or marks the receipt refunded');
check((int)renewalValue("SELECT COUNT(*) FROM admin_audit_logs WHERE target_id=? AND action='task_investigate'",[$alertTask['id']])===1,'continued investigation is audited once');
// Finish this fixture via existing audited evidence-based fill-in so it cannot block later test candidates.
$db->prepare("UPDATE tasks SET payload=JSON_SET(payload,'$.investigation_started_at',?) WHERE id=?")->execute([time()-3601,$alertTask['id']]);runTask($alertVid);$alertTask=task($alertVid);
$resolution->resolve($admin,(int)$alertTask['id'],['action'=>'fulfill','expected_execution_token'=>(int)$alertTask['execution_token'],'evidence'=>'TEST-ALERT: operator confirms original resource was renewed outside the fixture','remote_instance_id'=>$paidInstance['remote_instance_id']]);

// A paid upstream renewal hidden by temporary query failures only needs its original task filled in.
fixtureRenew('/__renew_mode?mode=normal');$delivered=resource();$di=(int)$delivered['instance_id'];$dv=$renew->issue($user,$di);$dvid=(int)$dv['invoice_id'];$billing->pay($user,$dvid);fixtureRenew('/__renew_mode?mode=drop_payment');runTask($dvid);$dt=task($dvid);$operation=$dt['upstream_task_id'];
fixtureRenew('/__wrong_host_id?enabled=1');$db->prepare("UPDATE tasks SET payload=JSON_SET(payload,'$.investigation_started_at',?) WHERE id=?")->execute([time()-3601,$dt['id']]);runTask($dvid);$dt=task($dvid);
check($dt['status']==='failed' && $operation!==null,'temporary query ownership failure preserves paid upstream renewal for operator review');
$prior=fixtureRenew('/__state');$beforeBalance=renewalValue('SELECT balance FROM users WHERE id=?',[$user]);$due=row('instances',$di)['due_date'];fixtureRenew('/__wrong_host_id?enabled=0');
$resolution->resolve($admin,(int)$dt['id'],['action'=>'investigate','expected_execution_token'=>(int)$dt['execution_token'],'evidence'=>'TEST-ALERT: upstream query restored; inspect existing paid renewal only']);runTask($dvid);
check(task($dvid)['status']==='success' && task($dvid)['upstream_task_id']===$operation && row('invoices',$dvid)['status']==='paid' && row('instances',$di)['due_date']===$due && fixtureRenew('/__state')===$prior && renewalValue('SELECT balance FROM users WHERE id=?',[$user])===$beforeBalance,'recovered upstream confirmation only fills status without another payment, extension or refund');
$logger->popHandler();fixtureRenew('/__renew_mode?mode=normal');
echo "Renewal checks: $checks\n";
