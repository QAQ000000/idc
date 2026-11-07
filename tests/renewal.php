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
$db->exec("DELETE FROM system_settings WHERE `key`='payment.epay'");
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
echo "Renewal checks: $checks\n";
