<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
set_exception_handler(static function(Throwable $e):never{fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
use App\Core\{Database,ApiException,Security};
use App\Services\{AdminAuthService,AuthService,BillingService,ConnectionSetupService,InstanceRenewalPriceService,OutboxWorker,PaymentService,RenewalService};
if(config('app.env')!=='test'||config('database.connections.mysql.database')!=='jianyun_test'){throw new RuntimeException('Isolated database required');}
$db=Database::getInstance();$checks=0;
function rpOperation(array $v):mixed {
    return match($v['operation']) {
        'price'=>(new InstanceRenewalPriceService())->update($v['admin'],$v['instance'],$v['input']),
        'pay'=>(new BillingService())->pay($v['user'],$v['invoice'],(bool)($v['automatic']??false)),
        'auto'=>(new RenewalService())->debitDue($v['user']),
        'notify'=>(new PaymentService())->notify('epay',$v['notice']),
        default=>throw new RuntimeException('Unknown isolated operation'),
    };
}
if(($argv[1]??'')==='--child') {
    $input=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
    echo 'READY:'.$db->query('SELECT CONNECTION_ID()')->fetchColumn()."\n";fflush(STDOUT);
    try{$result=rpOperation($input);echo json_encode(['status'=>200,'result'=>$result],JSON_THROW_ON_ERROR)."\n";}
    catch(ApiException $e){echo json_encode(['status'=>$e->httpStatus],JSON_THROW_ON_ERROR)."\n";}
    exit;
}
function rpCheck(bool $ok,string $label):void{global $checks;if(!$ok){throw new RuntimeException('FAIL price: '.$label);}$checks++;echo "PASS price: $label\n";}
function rpValue(string $sql,array $args=[]):mixed{global $db;$s=$db->prepare($sql);$s->execute($args);return $s->fetchColumn();}
function rpRow(string $table,int $id):array{global $db;$s=$db->prepare("SELECT * FROM $table WHERE id=?");$s->execute([$id]);return $s->fetch();}
function rpReject(callable $f,int $status,string $label):void{try{$f();}catch(ApiException $e){rpCheck($e->httpStatus===$status,$label);return;}throw new RuntimeException('Expected rejection: '.$label);}
function rpFixture(string $path):array{return json_decode(file_get_contents(getenv('UPSTREAM_TEST_URL').$path),true,64,JSON_THROW_ON_ERROR);}
function rpHttp(string $path,?string $token=null,string $method='GET',?array $body=null):array{
    $c=curl_init(getenv('TEST_BASE_URL').'/api/v1'.$path);$cache='';$payment=str_starts_with($path,'/payments/');
    curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_TIMEOUT=>25,CURLOPT_HTTPHEADER=>array_filter(['Content-Type: '.($payment?'application/x-www-form-urlencoded':'application/json'),$token?'Authorization: Bearer '.$token:null]),CURLOPT_HEADERFUNCTION=>static function($c,$line)use(&$cache){if(str_starts_with(strtolower($line),'cache-control:')){$cache=trim(substr($line,14));}return strlen($line);}]);
    if($body!==null){curl_setopt($c,CURLOPT_POSTFIELDS,$payment?http_build_query($body):json_encode($body,JSON_THROW_ON_ERROR));}
    $raw=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);
    return [$status,str_starts_with($path,'/payments/')?$raw:json_decode($raw,true,64,JSON_THROW_ON_ERROR),$cache];
}
function rpInput(int $id,?string $price='2.50'):array{global $service,$aid;return ['override_price'=>$price,'expected_version'=>$service->detail($aid,$id)['version'],'evidence'=>'PRICE-ACCEPTANCE: agreed future renewal price'];}
function rpSpawn(array $v):array{
    $p=proc_open([PHP_BINARY,__FILE__,'--child'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,dirname(__DIR__));
    fwrite($pipes[0],json_encode($v,JSON_THROW_ON_ERROR));fclose($pipes[0]);
    stream_set_timeout($pipes[1],10);$ready=trim(fgets($pipes[1])?:'');
    if(!preg_match('/^READY:([0-9]+)$/D',$ready,$m)){throw new RuntimeException('Child did not establish its own connection');}
    return [$p,$pipes,(int)$m[1]];
}
function rpWaiting(array $child):void{
    global $db;$deadline=microtime(true)+8;
    do{$s=$db->prepare("SELECT trx_state FROM information_schema.innodb_trx WHERE trx_mysql_thread_id=?");$s->execute([$child[2]]);if($s->fetchColumn()==='LOCK WAIT'){return;}usleep(100000);}while(microtime(true)<$deadline);
    throw new RuntimeException('Expected child to contend on the held instance row');
}
function rpFinish(array $child):array{
    [$p,$pipes]=$child;$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);
    if($exit!==0){throw new RuntimeException('Child failed: '.$err.$out);}
    return json_decode(trim($out),true,64,JSON_THROW_ON_ERROR);
}
function rpLock(int $id):void{global $db;$s=$db->prepare('SELECT id FROM instances WHERE id=? FOR UPDATE');$s->execute([$id]);$s->fetch();}
function rpFacts():array{global $db;$out=[];foreach(['instances','invoices','payment_records','transactions','tasks','users','admin_audit_logs'] as $table){$out[$table]=$db->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll();}return $out;}

rpFixture('/__mode?mode=normal');rpFixture('/__renew_mode?mode=normal');
$db->exec("UPDATE tasks SET status='success',lease_until=NULL WHERE status IN ('pending','running','investigating')");
$auth=new AdminAuthService();$ids=[];$tokens=[];
foreach(['super_admin','finance','support','operator'] as $role){$ids[$role]=$auth->create(['username'=>'price-'.$role,'email'=>'price-'.$role.'@example.test','password'=>'PriceAdmin!2026','role'=>$role]);$tokens[$role]=Security::generateToken(['uid'=>$ids[$role],'sub_type'=>'admin','role'=>'super_admin']);}
$aid=$ids['super_admin'];$at=$tokens['super_admin'];$service=new InstanceRenewalPriceService();$billing=new BillingService();$renew=new RenewalService();$worker=new OutboxWorker();$pay=new PaymentService();
$user=(new AuthService())->register(['email'=>'renewal-price@example.test','password'=>'PriceCustomer!2026']);$uid=(int)$user['user']['id'];
(new \App\Services\DemoService())->recharge($uid,'1000.00','price-initial-wallet');
$db->exec("DELETE FROM system_settings WHERE `key`='payment.epay'");
$setup=(new ConnectionSetupService())->install([
    'provider'=>['name'=>'price-fixture','driver'=>'zjmf','endpoint'=>getenv('UPSTREAM_TEST_URL'),'credentials'=>['username'=>'fixture','password'=>'fixture-secret']],
    'product'=>['remote_pid'=>391,'name'=>'Renewal price fixture CDN','type'=>'cdn','monthly_price'=>'0.01'],
    'payment'=>['driver'=>'epay','endpoint'=>'http://127.0.0.1:82','pid'=>'price-fixture','notify_url'=>'http://127.0.0.1/notify','return_url'=>'http://127.0.0.1','credentials'=>['key'=>'price-secret']],
]);$product=$setup['product_id'];
function rpResource():int{
    global $billing,$worker,$db,$uid,$product;
    $v=$billing->checkout($uid,['product_id'=>$product,'hostname'=>'PriceTest8','password'=>'PriceRoot!2026','billing_cycle'=>'monthly','use_balance'=>true]);
    $worker->runOnce('price-create');$id=(int)$v['instance_id'];
    $db->prepare('UPDATE instances SET auto_renew=0,due_date=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE id=?')->execute([$id]);return $id;
}
function rpInvoice(int $id):int{global $renew,$uid;return (int)$renew->issue($uid,$id)['invoice_id'];}
function rpNotice(int $invoice,string $trade):array{
    global $pay,$uid;$form=$pay->checkout($uid,$invoice,'epay')['checkout'];
    $n=['pid'=>'price-fixture','out_trade_no'=>$form['fields']['out_trade_no'],'trade_no'=>$trade,'money'=>$form['fields']['money'],'trade_status'=>'TRADE_SUCCESS','sign_type'=>'MD5'];
    $n['sign']=\Plugins\payments\epay\EpayDriver::sign($n,'price-secret');return $n;
}
function rpComplete(int $invoice):void{
    global $worker,$db;$s=$db->prepare('SELECT * FROM tasks WHERE invoice_id=? AND action=\'renew\'');$s->execute([$invoice]);$task=$s->fetch();
    // The fixture's cloud expiry and the local synthetic test date differ. Fenced completion
    // here isolates local pricing behavior, without pretending to verify an upstream renewal.
    $claim=$worker->claim('price-local-completion');
    if(!$claim||(int)$claim['id']!==(int)$task['id']){throw new RuntimeException('Unexpected completion claim');}
    $worker->finish($claim,new \App\Plugins\Providers\DriverExecutionResult('succeeded',$claim['payload']['remote_instance_id']));
}
$id=rpResource();$path='/admin/instances/'.$id.'/renewal-price';$old=rpRow('instances',$id);
foreach([null,$user['token'],Security::generateToken(['uid'=>$aid,'sub_type'=>'admin'],-1)] as $token){rpCheck(rpHttp($path,$token)[0]===401,'anonymous/customer/expired token cannot inspect pricing');}
foreach(['support','operator'] as $role){rpCheck(rpHttp($path,$tokens[$role])[0]===403&&rpHttp($path,$tokens[$role],'PATCH',rpInput($id))[0]===403,'nonfinancial role cannot read or edit pricing: '.$role);}
[$status,$r,$cache]=rpHttp($path,$tokens['finance']);rpCheck($status===200&&str_contains($cache,'no-store')&&!$r['data']['editable'],'finance can read prices without edit authority');
rpCheck(rpHttp($path,$tokens['finance'],'PATCH',rpInput($id))[0]===403,'finance edit denied even with valid version');
rpCheck(rpHttp('/admin/instances/999999/renewal-price',$at)[0]===404,'unknown instance hidden');
$detail=$service->detail($aid,$id);rpCheck($detail['frozen_price']==='0.01'&&$detail['effective_price']==='0.01'&&$detail['override_price']===null&&$detail['editable'],'original price projection and empty override');
rpCheck(!str_contains(json_encode($detail),'PriceRoot')&&!str_contains(json_encode($detail),'fixture-secret')&&!isset($detail['config_snapshot']),'price projection excludes secrets and config');
foreach([0,0.01,'0','-1','1.001','1e2','100000000.00',[],true] as $bad){$in=rpInput($id);$in['override_price']=$bad;rpReject(fn()=>$service->update($aid,$id,$in),400,'invalid money rejected: '.json_encode($bad));}
foreach([['evidence'=>'short'],['expected_version'=>'bad'],['override_price'=>'2.00','due_date'=>'2030-01-01'],['recurring_price'=>'3.00']] as $bad){$in=array_replace(rpInput($id),$bad);rpReject(fn()=>$service->update($aid,$id,$in),400,'unsupported/malformed pricing edit rejected');}
$in=rpInput($id);unset($in['override_price']);rpReject(fn()=>$service->update($aid,$id,$in),400,'omitted override is not interpreted as restore');
rpReject(fn()=>$service->update($aid,$id,rpInput($id,null)),409,'restore with no override is a no-op');
$inv=rpInvoice($id);$notice=rpNotice($inv,'price-old-cashier');$input=rpInput($id);$before=rpFacts();$counts=rpFixture('/__request_counts');
// Quote reconciliation may add an operational observation; capture immediately before the pricing edit.
$old=rpRow('instances',$id);
[$status,$r]=rpHttp($path,$at,'PATCH',$input);rpCheck($status===200&&$r['data']['cancelled_invoice_ids']===[$inv]&&$r['data']['effective_price']==='2.50','HTTP price edit cancels exactly the affected unpaid renewal');
rpCheck(rpRow('invoices',$inv)['status']==='cancelled'&&rpRow('invoices',$inv)['cycle_marker']===null,'cancellation and marker release commit together');
$i=rpRow('instances',$id);$copy=$i;foreach(['override_price','updated_at'] as $key){unset($copy[$key],$old[$key]);}rpCheck($copy===$old,'resource binding, original frozen price, period, config and service state preserved');
rpCheck(rpFixture('/__request_counts')===$counts,'price edit does not call upstream');
$after=rpFacts();foreach(['users','payment_records','transactions','tasks'] as $table){rpCheck($after[$table]===$before[$table],'pricing itself does not mutate '.$table);}
$audit=$service->detail($aid,$id)['audit'][0];rpCheck($audit['context']['before']['override_price']===null&&$audit['context']['after']['effective_price']==='2.50'&&$audit['context']['cancelled_invoice_ids']===[$inv],'audit binds evidence, before/after prices and cancelled IDs');
rpReject(fn()=>$service->update($aid,$id,$input),409,'replayed stale version rejected');
rpReject(fn()=>$billing->pay($uid,$inv),409,'cancelled old balance invoice cannot renew');
$balance=rpValue('SELECT balance FROM users WHERE id=?',[$uid]);$spent=rpValue('SELECT total_spent FROM users WHERE id=?',[$uid]);$due=$i['due_date'];
$notified=rpHttp('/payments/epay/notify',null,'POST',$notice);rpCheck($notified[0]===200&&$notified[1]==='success'&&rpValue("SELECT status FROM payment_records WHERE gateway_trade_no='price-old-cashier'")==='late_credited_to_balance','old cashier real receipt is accepted into wallet');
$pay->notify('epay',$notice);$pay->notify('epay',$notice);
rpCheck(rpValue('SELECT balance FROM users WHERE id=?',[$uid])===bcadd($balance,'0.01',2)&&rpValue('SELECT total_spent FROM users WHERE id=?',[$uid])===$spent,'duplicate late receipt credits wallet once, without consumption');
rpCheck(rpRow('invoices',$inv)['status']==='cancelled'&&rpRow('instances',$id)['due_date']===$due&&(int)rpValue('SELECT COUNT(*) FROM tasks WHERE invoice_id=?',[$inv])===0,'late old-price money cannot revive cancelled invoice or extend period');
$extra=$notice;$extra['trade_no']='price-old-cashier-extra';$extra['sign']=\Plugins\payments\epay\EpayDriver::sign($extra,'price-secret');$pay->notify('epay',$extra);$pay->notify('epay',$extra);
rpCheck(rpValue('SELECT balance FROM users WHERE id=?',[$uid])===bcadd($balance,'0.02',2)&&(int)rpValue("SELECT COUNT(*) FROM transactions WHERE invoice_id=? AND type='late_payment_credit'",[$inv])===2,'distinct extra actual receipt is not swallowed or counted twice');
$fresh=rpInvoice($id);rpCheck($fresh!==$inv&&rpRow('invoices',$fresh)['total']==='2.50'&&rpRow('invoices',$fresh)['cycle_marker']!==null,'same original period can issue a fresh invoice at override price');
$newNotice=rpNotice($fresh,'price-new-cashier');$pay->notify('epay',$newNotice);$pay->notify('epay',$newNotice);$paid=rpRow('invoices',$fresh);$bought=rpRow('instances',$id)['due_date'];
rpCheck($paid['status']==='paid'&&(int)rpValue("SELECT COUNT(*) FROM tasks WHERE invoice_id=? AND action='renew'",[$fresh])===1,'new valid cashier pays once and creates one renew task');
rpReject(fn()=>$service->update($aid,$id,rpInput($id,'3.00')),409,'paid pending renewal blocks price edit');rpComplete($fresh);
$stable=rpInput($id,null);$service->update($aid,$id,$stable);rpCheck($service->detail($aid,$id)['effective_price']==='0.01'&&rpRow('invoices',$fresh)===$paid&&rpRow('instances',$id)['due_date']===$bought,'restore uses original frozen price and preserves paid invoice and purchased period');
$aba=rpInput($id);$service->update($aid,$id,$aba);$service->update($aid,$id,rpInput($id,null));rpReject(fn()=>$service->update($aid,$id,$aba),409,'A to B to A history still rejects old version');
$stale=rpInput($id);$later=rpInvoice($id);rpReject(fn()=>$service->update($aid,$id,$stale),409,'new unpaid invoice after preview invalidates version');$billing->cancel($uid,$later);
$in=rpInput($id,'99999999.99');$service->update($aid,$id,$in);rpCheck($service->detail($aid,$id)['effective_price']==='99999999.99','maximum DECIMAL price accepted without float truncation');$service->update($aid,$id,rpInput($id,null));
// Two administrators editing the same version cannot both win.
$same=rpInput($id,'1.25');$rival=null;Database::transaction(function()use($id,$same,$service,$aid,&$rival){rpLock($id);$rival=rpSpawn(['operation'=>'price','admin'=>$aid,'instance'=>$id,'input'=>$same]);rpWaiting($rival);$service->update($aid,$id,$same);});
rpCheck(rpFinish($rival)['status']===409&&$service->detail($aid,$id)['effective_price']==='1.25','concurrent same-version price edits commit once');
rpReject(fn()=>$service->update($aid,$id,rpInput($id,'1.25')),409,'same normalized override does not cancel invoices or add audit');$service->update($aid,$id,rpInput($id,null));
$in=rpInput($id);$db->prepare("UPDATE admins SET role='finance' WHERE id=?")->execute([$aid]);rpReject(fn()=>$service->update($aid,$id,$in),403,'stored role downgrade overrides old super-admin token');$db->prepare("UPDATE admins SET role='super_admin' WHERE id=?")->execute([$aid]);
$db->prepare("UPDATE admins SET status='disabled' WHERE id=?")->execute([$aid]);rpCheck(rpHttp($path,$at)[0]===403,'disabled super-admin cannot read');$db->prepare("UPDATE admins SET status='active' WHERE id=?")->execute([$aid]);
// Every failed edit must preserve the invoices and prices together with its audit.
$rollback=rpResource();$rv=rpInvoice($rollback);$input=rpInput($rollback);$before=rpFacts();
$db->exec("CREATE TRIGGER price_audit_failure BEFORE INSERT ON admin_audit_logs FOR EACH ROW BEGIN IF NEW.action='instance_renewal_price' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated pricing audit fault'; END IF; END");
try{$service->update($aid,$rollback,$input);throw new RuntimeException('Audit fault missing');}catch(PDOException){}finally{$db->exec('DROP TRIGGER price_audit_failure');}
rpCheck(rpFacts()===$before,'audit insertion fault rolls back price, cancellation and cycle marker');
$other=(new AuthService())->register(['email'=>'price-other@example.test','password'=>'PriceOther!2026']);$db->prepare('UPDATE invoices SET user_id=? WHERE id=?')->execute([$other['user']['id'],$rv]);
$input['expected_version']=str_repeat('0',64);rpReject(fn()=>$service->update($aid,$rollback,$input),409,'mismatched renewal ownership fails before any mutation');$db->prepare('UPDATE invoices SET user_id=? WHERE id=?')->execute([$uid,$rv]);
// Existing gates protect unknown fulfillment, financial freezes and queued operations.
foreach([['power','pending'],['reinstall','pending'],['renew','pending'],['terminate','pending'],['create','running'],['renew','investigating'],['reinstall','failed']] as [$action,$state]){
    $input=rpInput($rollback);$db->prepare('INSERT INTO tasks(instance_id,action,idempotency_key,status,last_error,payload,lease_until) VALUES(?,?,?,?,?,\'{}\',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND))')->execute([$rollback,$action,'price-gate-'.bin2hex(random_bytes(8)),$state,$state==='failed'?'MANUAL_AUDIT_REQUIRED:UNKNOWN':null]);$tid=(int)$db->lastInsertId();
    rpCheck(!$service->detail($aid,$rollback)['editable'],'read projection exposes blocked '.$action.'/'.$state);rpReject(fn()=>$service->update($aid,$rollback,$input),409,'write preserves gate '.$action.'/'.$state);$db->prepare("UPDATE tasks SET status='success',last_error=NULL WHERE id=?")->execute([$tid]);
}
$db->prepare("UPDATE invoices SET status='refund_pending' WHERE id=?")->execute([$rv]);rpReject(fn()=>$service->update($aid,$rollback,rpInput($rollback)),409,'refund-pending invoice blocks pricing');$db->prepare("UPDATE invoices SET status='unpaid' WHERE id=?")->execute([$rv]);
$db->prepare("UPDATE payment_records SET refund_status='pending' WHERE invoice_id=(SELECT id FROM invoices WHERE instance_id=? AND type='new')")->execute([$rollback]);rpReject(fn()=>$service->update($aid,$rollback,rpInput($rollback)),409,'cloud receipt refund freeze blocks pricing');$db->prepare("UPDATE payment_records SET refund_status='none' WHERE invoice_id=(SELECT id FROM invoices WHERE instance_id=? AND type='new')")->execute([$rollback]);
foreach([['status'=>'pending'],['status'=>'terminated'],['remote_instance_id'=>null],['due_date'=>null],['billing_cycle'=>'onetime']] as $change){$key=array_key_first($change);$saved=rpRow('instances',$rollback)[$key];$db->prepare("UPDATE instances SET $key=? WHERE id=?")->execute([$change[$key],$rollback]);rpReject(fn()=>$service->update($aid,$rollback,rpInput($rollback)),409,'invalid resource/cycle state blocks pricing: '.$key);$db->prepare("UPDATE instances SET $key=? WHERE id=?")->execute([$saved,$rollback]);}
// Deterministic two-connection lock tests: first holder commits, second observes CURRENT facts.
foreach(['pay','auto','notify'] as $operation){
    $ri=rpResource();$iv=rpInvoice($ri);$n=rpNotice($iv,'price-first-'.$operation);$db->prepare('UPDATE instances SET auto_renew=1 WHERE id=?')->execute([$ri]);$edit=rpInput($ri,'4.00');$due=rpRow('instances',$ri)['due_date'];$balance=rpValue('SELECT balance FROM users WHERE id=?',[$uid]);
    $child=null;Database::transaction(function()use($ri,$iv,$n,$operation,$edit,$uid,&$child,$service,$aid){rpLock($ri);$child=rpSpawn(['operation'=>$operation,'invoice'=>$iv,'user'=>$uid,'notice'=>$n]);rpWaiting($child);$service->update($aid,$ri,$edit);});$result=rpFinish($child);
    rpCheck(($operation==='pay'?$result['status']===409:$result['status']===200)&&rpRow('invoices',$iv)['status']==='cancelled','price-first lock order rejects normal settlement: '.$operation);
    rpCheck(rpRow('instances',$ri)['due_date']===$due&&(int)rpValue('SELECT COUNT(*) FROM tasks WHERE invoice_id=?',[$iv])===0,'price-first race never extends or queues old invoice: '.$operation);
    rpCheck(rpValue('SELECT balance FROM users WHERE id=?',[$uid])===($operation==='notify'?bcadd($balance,'0.01',2):$balance),'price-first race keeps real funds correct: '.$operation);
    $db->prepare('UPDATE instances SET auto_renew=0 WHERE id=?')->execute([$ri]);
}
foreach(['pay','auto','notify'] as $operation){
    $ri=rpResource();$iv=rpInvoice($ri);$n=rpNotice($iv,'payment-first-'.$operation);$db->prepare('UPDATE instances SET auto_renew=1 WHERE id=?')->execute([$ri]);$edit=rpInput($ri,'5.00');$due=rpRow('instances',$ri)['due_date'];$balance=rpValue('SELECT balance FROM users WHERE id=?',[$uid]);
    $child=null;Database::transaction(function()use($ri,$iv,$n,$operation,$edit,$uid,&$child,$aid){rpLock($ri);$child=rpSpawn(['operation'=>'price','admin'=>$aid,'instance'=>$ri,'input'=>$edit]);rpWaiting($child);rpOperation(['operation'=>$operation,'invoice'=>$iv,'user'=>$uid,'notice'=>$n]);});$result=rpFinish($child);
    rpCheck($result['status']===409&&rpRow('invoices',$iv)['status']==='paid'&&rpRow('instances',$ri)['override_price']===null,'payment-first preserves paid old-price contract: '.$operation);
    rpCheck(rpRow('instances',$ri)['due_date']!==$due&&(int)rpValue('SELECT COUNT(*) FROM tasks WHERE invoice_id=?',[$iv])===1,'payment-first grants exactly one period and one task: '.$operation);
    rpCheck(rpValue('SELECT balance FROM users WHERE id=?',[$uid])===($operation==='notify'?$balance:bcsub($balance,'0.01',2)),'payment-first wallet charged only for balance settlement: '.$operation);
    $db->prepare('UPDATE instances SET auto_renew=0 WHERE id=?')->execute([$ri]);rpComplete($iv);
}
// Simultaneous old cashier late receipts and the new-price cashier buy only one period.
$ri=rpResource();$iv=rpInvoice($ri);$oldNotice=rpNotice($iv,'price-parallel-old');$service->update($aid,$ri,rpInput($ri,'3.00'));$new=rpInvoice($ri);$newNotice=rpNotice($new,'price-parallel-new');$balance=rpValue('SELECT balance FROM users WHERE id=?',[$uid]);$children=[];
foreach([$oldNotice,$oldNotice,$newNotice,$newNotice] as $n){$children[]=rpSpawn(['operation'=>'notify','notice'=>$n]);}
foreach($children as $c){rpCheck(rpFinish($c)['status']===200,'concurrent old/new duplicate callbacks accepted');}
rpCheck(rpValue('SELECT balance FROM users WHERE id=?',[$uid])===bcadd($balance,'0.01',2)&&rpRow('invoices',$iv)['status']==='cancelled'&&rpRow('invoices',$new)['status']==='paid','old receipt wallet credit and new receipt cloud purchase remain distinct');
rpCheck((int)rpValue('SELECT COUNT(*) FROM tasks WHERE invoice_id IN (?,?)',[$iv,$new])===1&&(int)rpValue("SELECT COUNT(*) FROM transactions WHERE invoice_id=? AND type='late_payment_credit'",[$iv])===1,'parallel duplicates produce one credit and one renew task');rpComplete($new);
echo "Instance renewal price checks: $checks\n";
