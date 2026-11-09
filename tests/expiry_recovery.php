<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use App\Core\{ApiException,Database,Security};
use App\Services\{AuthService,AdminAuthService,BillingService,ConnectionSetupService,InstanceService,InstanceServiceStatusService,OutboxWorker,PaymentService,RenewalService,RenewalWakeupService,WalletService};
if(getenv('APP_ENV')!=='test'||getenv('DB_DATABASE')!=='jianyun_test'){throw new RuntimeException('Disposable DB required');}
set_exception_handler(static function(Throwable $e):never{fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
$db=Database::getInstance();$checks=0;$billing=new BillingService();$renew=new RenewalService();$status=new InstanceServiceStatusService();$worker=new OutboxWorker();
function ec(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException('FAIL expiry: '.$why);$checks++;echo "PASS expiry: $why\n";}
function ev(string $sql,array $args=[]):mixed{global $db;$s=$db->prepare($sql);$s->execute($args);return $s->fetchColumn();}
function er(int $id):array{global $db;$s=$db->prepare('SELECT * FROM instances WHERE id=?');$s->execute([$id]);return $s->fetch();}
function ef(string $path):array{return json_decode(file_get_contents(getenv('UPSTREAM_TEST_URL').$path),true,32,JSON_THROW_ON_ERROR);}
function es(int $id,string $state,string $reason='due'):void{ef('/__service?'.http_build_query(['hostid'=>substr(er($id)['remote_instance_id'],5),'status'=>$state,'reason'=>$reason]));}
function denied(callable $f,int $expected=409):bool{try{$f();return false;}catch(ApiException $e){return $e->httpStatus===$expected;}}
function eh(string $path,?string $token=null,string $method='POST'):array{
 $c=curl_init(getenv('TEST_BASE_URL').'/api/v1'.$path);curl_setopt_array($c,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_HEADER=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json',...($token?['Authorization: Bearer '.$token]:[])]]);$raw=curl_exec($c);$size=curl_getinfo($c,CURLINFO_HEADER_SIZE);$code=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);return [$code,json_decode(substr($raw,$size),true),substr($raw,0,$size)];
}
$db->exec("UPDATE tasks SET status='success',lease_until=NULL WHERE status IN ('pending','running','investigating')");
ef('/__mode?mode=normal');ef('/__power_mode?mode=normal');ef('/__reinstall_mode?mode=normal');
require_once __DIR__.'/reset_payment_fixture.php';resetPaymentFixture();
$setup=(new ConnectionSetupService())->install(['provider'=>['name'=>'expiry-fixture','driver'=>'zjmf','endpoint'=>getenv('UPSTREAM_TEST_URL'),'credentials'=>['username'=>'expiry-fixture','password'=>'NEVER-EXPOSE-SERVICE-KEY']], 'product'=>['remote_pid'=>391,'name'=>'CDN expiry fixture','type'=>'cdn','monthly_price'=>'0.01'], 'payment'=>['driver'=>'epay','endpoint'=>'http://127.0.0.1:82','pid'=>'expiry-fixture','notify_url'=>'http://127.0.0.1/notify','return_url'=>'http://127.0.0.1','credentials'=>['key'=>'expiry-fixture-key']]]);
$pid=(int)$setup['product_id'];$auth=(new AuthService())->register(['email'=>'expiry@example.test','password'=>'ExpiryFixture!2026']);$uid=(int)$auth['user']['id'];
$db->prepare('UPDATE users SET balance=10 WHERE id=?')->execute([$uid]);
function resource():int{
 global $billing,$worker,$db,$uid,$pid;$v=$billing->checkout($uid,['product_id'=>$pid,'hostname'=>'expiry-fixture','password'=>'ExpiryFixture12','billing_cycle'=>'monthly','use_balance'=>true]);$worker->runOnce('expiry-create');$id=(int)$v['instance_id'];
 if(er($id)['status']!=='active')throw new RuntimeException('Expiry fixture not delivered');
 $due=gmdate('Y-m-d H:i:s',time()-86400);$db->prepare('UPDATE instances SET due_date=?,auto_renew=0 WHERE id=?')->execute([$due,$id]);
 ef('/__service?'.http_build_query(['hostid'=>substr(er($id)['remote_instance_id'],5),'due'=>strtotime($due.' UTC')]));return $id;
}
function et(int $invoice):array{global $db;$s=$db->prepare('SELECT * FROM tasks WHERE invoice_id=?');$s->execute([$invoice]);return $s->fetch();}
function runRenew(int $invoice):void{global $worker,$db;$t=et($invoice);$db->prepare('UPDATE tasks SET next_retry_at=NULL WHERE id=?')->execute([$t['id']]);$worker->runOnce('expiry-renew');}
function en(int $invoice,string $key):array{global $uid;$form=(new PaymentService())->checkout($uid,$invoice,'epay')['checkout'];$n=['pid'=>'expiry-fixture','out_trade_no'=>$form['fields']['out_trade_no'],'trade_no'=>$key,'money'=>$form['fields']['money'],'trade_status'=>'TRADE_SUCCESS','sign_type'=>'MD5'];$n['sign']=\Plugins\payments\epay\EpayDriver::sign($n,'expiry-fixture-key');return $n;}
$id=resource();$original=er($id);$before=ef('/__request_counts');$r=$status->reconcile($id,$uid);$i=er($id);
ec($r['applied']&&$r['entitlement_state']==='expired'&&$r['service_observation']['state']==='active'&&$i['status']==='active','overdue entitlement does not invent upstream suspension');
ef('/__power_state?hostid='.substr($i['remote_instance_id'],5).'&state=off');
[$powerDriver,$powerCredentials]=(new \App\Services\ProviderService())->resolve((int)$i['provider_id']);
ec($powerDriver->inspectPower(\App\Services\InstancePowerService::context($i),$powerCredentials)->state==='off'&&$status->reconcile($id)['service_observation']['state']==='active','VM power off and upstream service Active remain independent');
es($id,'Suspended');$r=$status->reconcile($id);$i=er($id);
ec($i['status']==='suspended'&&$i['suspend_type']==='billing'&&$r['service_observation']['suspension']==='billing','structured due evidence records billing suspension');
foreach(['due_date','remote_instance_id','provider_id','product_id','recurring_price','override_price','encrypted_password'] as $key)ec($i[$key]===$original[$key],'reconciliation preserves '.$key);
$d=(new InstanceService())->detail($uid,$id);ec($d['delivery_status']==='delivered'&&$d['entitlement_state']==='expired','suspended delivered resource never appears provisioning');
$counts=ef('/__request_counts');ec(!isset($counts['/host/suspend'],$counts['/host/terminate'],$counts['/host/unsuspend'])&&($counts['/provision/default']??0)===($before['/provision/default']??0)+1,'reconciliation sends no suspend, release or power change commands');
// A query's operational snapshot cannot alter immutable sale inputs or funding facts.
$snap=json_decode($i['config_snapshot'],true);unset($snap['service_status']);$old=json_decode($original['config_snapshot'],true);ec($snap===$old,'only allowlisted operational observation is added to snapshot');
es($id,'Active');ec($status->reconcile($id)['service_observation']['review_required']&&er($id)['status']==='suspended','Active alone never clears a local hold');
es($id,'Suspended');$status->reconcile($id);
// Unknown reason is not a billing inference; a subsequent exact due can correct this module's own hold.
$unknown=resource();es($unknown,'Suspended','');$r=$status->reconcile($unknown);ec($r['service_observation']['review_required']&&er($unknown)['suspend_type']==='manual','missing reason persists manual review instead of guessing from expired date');
ec(denied(fn()=>$renew->issue($uid,$unknown)),'unknown suspension blocks new renewal billing');
es($unknown,'Suspended','due');$status->reconcile($unknown);ec(er($unknown)['suspend_type']==='billing'&&!InstanceServiceStatusService::renewalBlocked(er($unknown)),'confirmed due corrects only this module generated unknown hold');
foreach(['flow','uncertifi','self'] as $reason){$x=resource();es($x,'Suspended',$reason);$r=$status->reconcile($x);ec($r['service_observation']['suspension']==='nonbilling'&&$r['service_observation']['review_required']&&denied(fn()=>$renew->issue($uid,$x)),$reason.' suspension is never automatically renewed');}
foreach(['manual','compliance','abuse'] as $reason){$x=resource();$db->prepare("UPDATE instances SET status='suspended',suspend_type=?,suspend_reason='independent hold' WHERE id=?")->execute([$reason,$x]);es($x,'Suspended');$status->reconcile($x);ec(er($x)['suspend_type']===$reason&&er($x)['suspend_reason']==='independent hold','upstream due preserves independent '.$reason.' restriction');}
foreach(['Terminated','Cancelled','Pending'] as $state){$x=resource();es($x,$state);$r=$status->reconcile($x);ec(er($x)['status']==='active'&&$r['service_observation']['review_required'],'remote '.$state.' enters review without local destruction or funding changes');}
$x=resource();es($x,'Suspended');
foreach(['unavailable','header_unavailable','wrong_header','header_due','header_product','header_cycle','header_state'] as $mode){ef('/__service_mode?mode='.$mode);$r=$status->reconcile($x);ec($r['service_observation']['state']==='unknown'&&$r['service_observation']['review_required']&&er($x)['status']==='active','untrusted query '.$mode.' cannot change local service status');}
ef('/__service_mode?mode=header_type');$r=$status->reconcile($x);ec($r['service_observation']['suspension']==='unknown'&&er($x)['suspend_type']==='manual'&&$r['service_observation']['review_required'],'malformed reason preserves verified suspension but never infers billing');
ef('/__service_mode?mode=normal');ef('/__wrong_host_id?enabled=1');ec($status->reconcile($x)['service_observation']['state']==='unknown','hostinfo ownership mismatch is rejected');ef('/__wrong_host_id?enabled=0');
// Existing fixed invoice survives query outage; payment nevertheless obeys the recorded review gate.
$x=resource();$invoice=(int)$renew->issue($uid,$x)['invoice_id'];$unknownNotice=en($invoice,'expiry-unknown-receipt');$unknownBalance=ev('SELECT balance FROM users WHERE id=?',[$uid]);$unknownDue=er($x)['due_date'];ef('/__service_mode?mode=unavailable');$status->reconcile($x);
ec((int)$renew->issue($uid,$x)['invoice_id']===$invoice&&denied(fn()=>$billing->pay($uid,$invoice)),'original invoice remains findable during outage but uncertain service blocks debit');
(new PaymentService())->notify('epay',$unknownNotice);(new PaymentService())->notify('epay',$unknownNotice);
ec(ev('SELECT balance FROM users WHERE id=?',[$uid])===bcadd($unknownBalance,'0.01',2)&&er($x)['due_date']===$unknownDue&&ev('SELECT status FROM invoices WHERE id=?',[$invoice])==='unpaid'&&(int)ev('SELECT COUNT(*) FROM tasks WHERE invoice_id=?',[$invoice])===0,'real receipt during service uncertainty credits wallet once without lost funds or unsafe renewal');
ef('/__service_mode?mode=normal');es($x,'Active');$status->reconcile($x);$billing->cancel($uid,$invoice);
// Charged renewal whose suspension reason changes after quote must stop before any remote debit.
$x=resource();$invoice=(int)$renew->issue($uid,$x)['invoice_id'];$billing->pay($uid,$invoice);$p=er($x);$balance=ev('SELECT balance FROM users WHERE id=?',[$uid]);es($x,'Suspended','self');$before=ef('/__state');runRenew($invoice);$t=et($invoice);
ec($t['status']==='failed'&&str_starts_with($t['last_error'],'MANUAL_AUDIT_REQUIRED:')&&ef('/__state')['renew_calls']===$before['renew_calls'],'execute time reason gate sends paid nonbilling renewal to operator without remote request');
ec(ev('SELECT status FROM invoices WHERE id=?',[$invoice])==='paid'&&er($x)['due_date']===$p['due_date']&&ev('SELECT balance FROM users WHERE id=?',[$uid])===$balance,'failed renewal preserves paid funds, purchased period and original binding');
ec($status->reconcile($x)['outcome']==='OPERATION_BLOCKED','manual task retains business occupancy even without live lease');
// Actual recharge settlement -> original opted-in renewal debit -> one fenced restore task.
$db->prepare('UPDATE users SET balance=1 WHERE id=?')->execute([$uid]);$x=resource();es($x,'Suspended');$status->reconcile($x);$base=er($x)['due_date'];$invoice=(int)$renew->issue($uid,$x)['invoice_id'];$db->prepare('UPDATE instances SET auto_renew=1 WHERE id=?')->execute([$x]);$db->prepare('UPDATE users SET balance=0 WHERE id=?')->execute([$uid]);
$topup=(new WalletService())->recharge($uid,['amount'=>'0.01','request_key'=>'expiry-recovery-topup']);$notice=en((int)$topup['invoice_id'],'expiry-topup');$payment=new PaymentService();$payment->notify('epay',$notice);$payment->notify('epay',$notice);
(new RenewalWakeupService())->runOnce();$renew->debitDue($uid);$renew->debitDue($uid);
ec(ev('SELECT status FROM invoices WHERE id=?',[$invoice])==='paid'&&ev('SELECT balance FROM users WHERE id=?',[$uid])==='0.00'&&(int)ev('SELECT COUNT(*) FROM tasks WHERE invoice_id=?',[$invoice])===1,'duplicate recharge and competing fallback debit original invoice exactly once');
$paidDue=er($x)['due_date'];ec($paidDue!==$base&&er($x)['status']==='suspended','payment extends one purchased period but never claims service restored');
ef('/__renew_mode?mode=hold_restore');$before=ef('/__state');runRenew($invoice);$t=et($invoice);ec($t['status']==='investigating'&&er($x)['status']==='suspended','remote renewal accepted without restore keeps billing suspension and original investigation');
ec($status->reconcile($x)['outcome']==='OPERATION_BLOCKED','status scan cannot overwrite an investigating renewal');
es($x,'Active');runRenew($invoice);$after=ef('/__state');ec(et($invoice)['status']==='success'&&er($x)['status']==='active'&&er($x)['due_date']===$paidDue&&$after['renew_calls']===$before['renew_calls']+1&&$after['renew_payments']===$before['renew_payments']+1,'read-only restore confirmation clears hold without second debit or period extension');
ec(!InstanceServiceStatusService::renewalBlocked(er($x))&&InstanceServiceStatusService::summary(er($x))['service_observation']['code']==='RENEWAL_CONFIRMED','confirmed renewal refreshes safe service observation');
ef('/__renew_mode?mode=normal');
$db->prepare('UPDATE users SET balance=1 WHERE id=?')->execute([$uid]);$lost=resource();es($lost,'Suspended');$status->reconcile($lost);$invoice=(int)$renew->issue($uid,$lost)['invoice_id'];$billing->pay($uid,$invoice);ef('/__renew_mode?mode=drop_payment');$before=ef('/__state');runRenew($invoice);ec(et($invoice)['status']==='investigating','lost renewal response retains investigation');runRenew($invoice);$after=ef('/__state');ec(et($invoice)['status']==='success'&&er($lost)['status']==='active'&&$after['renew_calls']===$before['renew_calls']+1&&$after['renew_payments']===$before['renew_payments']+1,'lost response recovery observes same original renewal and restores once');ef('/__renew_mode?mode=normal');
// Query performs no business write if instance, provider, task or authority changed during network read.
$x=resource();es($x,'Suspended');
function raceRead(int $id,callable $change,?int $admin=null):array{
 ef('/__service_mode?mode=delay_read');$before=ef('/__request_counts')['/cart/hostinfo']??0;
 $body='require '.var_export(dirname(__DIR__).'/bootstrap.php',true).'; try { echo json_encode((new App\\Services\\InstanceServiceStatusService())->reconcile('.$id.',null,'.var_export($admin,true).')); } catch(App\\Core\\ApiException $e) {echo json_encode(["http"=>$e->httpStatus]);}';
 $proc=proc_open([PHP_BINARY,'-r',$body],[1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
 try{$end=microtime(true)+8;while((ef('/__request_counts')['/cart/hostinfo']??0)<=$before){if(microtime(true)>$end)throw new RuntimeException('Query never reached fixture');usleep(20000);}$change();$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($proc)!==0)throw new RuntimeException($error);$proc=null;return json_decode($out,true,32,JSON_THROW_ON_ERROR);}finally{if(is_resource($proc)){proc_terminate($proc);proc_close($proc);}ef('/__service_mode?mode=normal');}
}
$r=raceRead($x,fn()=>$db->prepare('UPDATE instances SET due_date=DATE_ADD(due_date,INTERVAL 1 DAY) WHERE id=?')->execute([$x]));ec(!$r['applied']&&$r['outcome']==='LOCAL_STATE_CHANGED'&&er($x)['status']==='active','concurrent entitlement change fences stale upstream suspension');
$r=raceRead($x,fn()=>$db->prepare('UPDATE providers SET name=CONCAT(name,\' renamed\') WHERE id=?')->execute([$setup['provider_id']]));ec($r['applied'],'provider display-name edit does not change immutable connection identity');
$r=raceRead($x,fn()=>$db->prepare('UPDATE providers SET status=0 WHERE id=?')->execute([$setup['provider_id']]));ec(!$r['applied']&&$r['outcome']==='LOCAL_STATE_CHANGED','provider configuration change fences old observation');$db->prepare('UPDATE providers SET status=1 WHERE id=?')->execute([$setup['provider_id']]);
$local=er($x);$db->prepare("UPDATE instances SET status='active',suspend_type='billing' WHERE id=?")->execute([$x]);
$r=raceRead($x,fn()=>$db->prepare("UPDATE tasks SET execution_token=execution_token+1 WHERE instance_id=?")->execute([$x]));ec(!$r['applied']&&$r['outcome']==='LOCAL_STATE_CHANGED','task token change fences old observation even if task terminal');
$admins=new AdminAuthService();$aid=$admins->create(['username'=>'expiry-admin','email'=>'expiry-admin@example.test','password'=>'ExpiryAdmin!2026','role'=>'super_admin']);$support=$admins->create(['username'=>'expiry-support','email'=>'expiry-support@example.test','password'=>'ExpiryAdmin!2026','role'=>'support']);
$r=raceRead($x,fn()=>$db->prepare("UPDATE admins SET role='support' WHERE id=?")->execute([$aid]),$aid);ec(($r['http']??null)===403,'admin downgrade during read prevents observation write');$db->prepare("UPDATE admins SET role='super_admin' WHERE id=?")->execute([$aid]);
$token=$auth['token'];$at=Security::generateToken(['uid'=>$aid,'sub_type'=>'admin']);$st=Security::generateToken(['uid'=>$support,'sub_type'=>'admin']);$path='/instances/'.$x.'/service-status';
ec(eh($path)[0]===401&&eh($path,$at)[0]===401,'customer status endpoint rejects anonymous and admin tokens');
$other=(new AuthService())->register(['email'=>'expiry-other@example.test','password'=>'ExpiryOther!2026']);ec(eh($path,$other['token'])[0]===404,'foreign instance cannot be probed');
[$code,$data,$headers]=eh($path,$token);ec($code===200&&str_contains($headers,'no-store')&&$data['data']['applied'],'owner reconciles without response caching');
ec(eh('/admin'.$path,$st)[0]===403&&eh('/admin'.$path,$token)[0]===401&&eh('/admin'.$path,$at)[0]===200,'admin status write strictly requires active super admin');
$review=resource();es($review,'Suspended','');$status->reconcile($review);[$code,$data]=eh('/admin/instances?instance_id='.$review.'&service_review=1',$st,'GET');ec($code===200&&$data['data']['total']===1,'support can locate persisted service review without financial rights');
ec(eh('/admin/instances?service_review=0',$at,'GET')[0]===400&&eh('/admin/instances?service_review[]=1',$at,'GET')[0]===400,'review filter input validated');
$encoded=json_encode(eh('/admin/instances/'.$review,$st,'GET')[1]);ec(!str_contains($encoded,'NEVER-EXPOSE')&&!str_contains($encoded,'config_snapshot')&&!str_contains($encoded,'endpoint')&&!str_contains($encoded,'encrypted_password'),'safe status and admin projection never disclose raw provider data or credentials');
// Refund and pending work retain original occupancy; no scan can bypass them.
$x=resource();$newInvoice=(int)ev("SELECT id FROM invoices WHERE instance_id=? AND type='new'",[$x]);$db->prepare("UPDATE invoices SET status='refund_pending' WHERE id=?")->execute([$newInvoice]);ec($status->reconcile($x)['outcome']==='OPERATION_BLOCKED','refund freeze blocks state reconciliation');$db->prepare("UPDATE invoices SET status='paid' WHERE id=?")->execute([$newInvoice]);
$db->prepare("INSERT INTO tasks(instance_id,invoice_id,idempotency_key,action,payload) VALUES(?,?,?,'power','{}')")->execute([$x,$newInvoice,'expiry-pending-'.$x]);$pending=(int)$db->lastInsertId();ec($status->reconcile($x)['outcome']==='OPERATION_BLOCKED','queued conflicting work retains occupancy');$db->prepare("UPDATE tasks SET status='success' WHERE id=?")->execute([$pending]);
// Idle scan only, same MySQL observation governs duplicate scans; no extra queue/daemon.
$oldProviders=$db->query('SELECT id,status FROM providers')->fetchAll();$db->exec('UPDATE providers SET status=0');$db->prepare('UPDATE providers SET status=1 WHERE id=?')->execute([$setup['provider_id']]);
$db->prepare("UPDATE instances SET config_snapshot=JSON_SET(config_snapshot,'$.service_status',COALESCE(JSON_EXTRACT(config_snapshot,'$.service_status'),JSON_OBJECT()),'$.service_status.checked_at',?) WHERE provider_id=?")->execute([gmdate('Y-m-d H:i:s'),$setup['provider_id']]);
$scan=resource();$financial=(int)ev('SELECT COUNT(*) FROM transactions');$tasks=(int)ev('SELECT COUNT(*) FROM tasks');$before=ef('/__request_counts')['/cart/hostinfo'];
ec($status->runOnce()&&InstanceServiceStatusService::summary(er($scan))['service_observation']['state']==='active','idle Worker scan finds overdue resource');ec(!$status->runOnce()&&(ef('/__request_counts')['/cart/hostinfo']??0)===$before+1,'repeat scan respects persistent fifteen-minute throttle');
ec((int)ev('SELECT COUNT(*) FROM transactions')===$financial&&(int)ev('SELECT COUNT(*) FROM tasks')===$tasks,'status scan creates no funds, lifecycle tasks or refunds');
$db->prepare("UPDATE instances SET config_snapshot=JSON_REMOVE(config_snapshot,'$.service_status.checked_at') WHERE id=?")->execute([$scan]);
$command=proc_open([PHP_BINARY,'artisan','outbox:work','--once'],[1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes,dirname(__DIR__));ec(proc_close($command)===0&&InstanceServiceStatusService::summary(er($scan))['service_observation']['checked_at']===null,'--once preserves original behavior without service reconciliation');
$command=proc_open([PHP_BINARY,'artisan','outbox:work'],[1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes,dirname(__DIR__));
try{$end=microtime(true)+8;while(InstanceServiceStatusService::summary(er($scan))['service_observation']['checked_at']===null){if(microtime(true)>$end)throw new RuntimeException('Continuous Worker did not reconcile idle resource');usleep(50000);}ec(true,'continuous original Worker loads and performs idle service reconciliation');}finally{proc_terminate($command);proc_close($command);}
foreach($oldProviders as $p)$db->prepare('UPDATE providers SET status=? WHERE id=?')->execute([$p['status'],$p['id']]);
ef('/__service_mode?mode=normal');
echo "Expiry recovery checks: $checks\n";
