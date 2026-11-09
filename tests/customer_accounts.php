<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use App\Core\{ApiException,Database,Security};
use App\Middlewares\AuthMiddleware;
use App\Services\{AdminAccountService,AdminAuthService,AuthService,BillingService,CustomerAccountService,DemoService,OutboxWorker,PasswordService,PaymentService,PaymentSettingsService,ProductSnapshotService,RenewalService,WalletService};
if(config('app.env')!=='test'||config('database.connections.mysql.database')!=='jianyun_test'){throw new RuntimeException('Isolated DB required');}
set_exception_handler(static function(Throwable $e):never{fwrite(STDERR,(string)$e.PHP_EOL);exit(1);});
$db=Database::getInstance();$checks=0;$service=new CustomerAccountService();
if(($argv[1]??'')==='--child'){
    $v=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
    if(isset($v['token'])){$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$v['token'];$_SERVER['REQUEST_METHOD']='POST';$_SERVER['REQUEST_URI']='/api/v1/wallet/recharges';(new AuthMiddleware())->handle();}
    echo 'READY:'.$db->query('SELECT CONNECTION_ID()')->fetchColumn()."\n";fflush(STDOUT);
    try{$r=match($v['op']){
        'change'=>$service->change($v['actor'],$v['session'],$v['user'],$v['body'],$v['password']??false),
        'pay'=>(new BillingService())->pay($v['user'],$v['invoice'],true),
        'recharge'=>(new WalletService())->recharge($v['user'],['amount'=>'0.01','request_key'=>'accounts-waiting-recharge']),
    };echo json_encode(['status'=>200,'data'=>$r],JSON_THROW_ON_ERROR)."\n";}catch(ApiException $e){echo json_encode(['status'=>$e->httpStatus],JSON_THROW_ON_ERROR)."\n";}exit;
}
function caCheck(bool $v,string $why):void{global $checks;if(!$v){throw new RuntimeException('FAIL customer accounts: '.$why);}++$checks;echo 'PASS customer accounts: '.$why.PHP_EOL;}
function caVal(string $sql,array $a=[]):mixed{global $db;$s=$db->prepare($sql);$s->execute($a);return $s->fetchColumn();}
function caReject(callable $f,int $code,string $why):void{try{$f();}catch(ApiException $e){caCheck($e->httpStatus===$code,$why);return;}throw new RuntimeException('Expected rejection: '.$why);}
function caUuid():string{$h=bin2hex(random_bytes(16));return substr($h,0,8).'-'.substr($h,8,4).'-4'.substr($h,13,3).'-a'.substr($h,17,3).'-'.substr($h,20);}
function caSession(int $id):int{global $db;return AdminAuthService::sessionVersion($db,$id);}
function caToken(int $id):string{return Security::generateToken(['uid'=>$id,'sub_type'=>'admin','av'=>caSession($id)]);}
function caGet(int $id):array{global $service,$aid;return $service->detail($aid,caSession($aid),$id);}
function caBody(int $id,string $status='suspended'):array{return ['status'=>$status,'expected_version'=>caGet($id)['account']['version'],'request_key'=>caUuid(),'evidence'=>'CUSTOMER-ACCOUNT: explicit authorized acceptance','confirmed'=>true];}
function caChange(int $id,string $status):void{global $service,$aid;$service->change($aid,caSession($aid),$id,caBody($id,$status));}
function caHttp(string $path,?string $token=null,string $method='GET',?array $body=null):array{
    $h=curl_init(getenv('TEST_BASE_URL').'/api/v1'.$path);$cache='';$notify=str_contains($path,'/payments/epay/notify');
    curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_TIMEOUT=>25,CURLOPT_HTTPHEADER=>array_filter(['Content-Type: '.($notify?'application/x-www-form-urlencoded':'application/json'),$token?'Authorization: Bearer '.$token:null]),CURLOPT_HEADERFUNCTION=>static function($h,$line)use(&$cache){if(str_starts_with(strtolower($line),'cache-control:')){$cache=trim(substr($line,14));}return strlen($line);}]);
    if($body!==null){curl_setopt($h,CURLOPT_POSTFIELDS,$notify?http_build_query($body):json_encode($body?:new stdClass(),JSON_THROW_ON_ERROR));}$raw=curl_exec($h);$code=curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);return [$code,$notify?$raw:json_decode($raw,true,64,JSON_THROW_ON_ERROR),$cache];
}
function caSpawn(array $v):array{$p=proc_open([PHP_BINARY,__FILE__,'--child'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,dirname(__DIR__));fwrite($pipes[0],json_encode($v,JSON_THROW_ON_ERROR));fclose($pipes[0]);stream_set_timeout($pipes[1],15);$line=trim(fgets($pipes[1])?:'');if(!preg_match('/^READY:(\d+)$/D',$line,$m)){throw new RuntimeException('Child not ready: '.$line);}return [$p,$pipes,(int)$m[1]];}
function caWaiting(array $c):void{$end=microtime(true)+8;do{if(caVal('SELECT trx_state FROM information_schema.innodb_trx WHERE trx_mysql_thread_id=?',[$c[2]])==='LOCK WAIT'){return;}usleep(100000);}while(microtime(true)<$end);throw new RuntimeException('No real customer lock wait observed');}
function caFinish(array $c):array{[$p,$pipes]=$c;$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0){throw new RuntimeException('Child failed: '.$out.$err);}return json_decode(trim($out),true,32,JSON_THROW_ON_ERROR);}
function caResetToken(int $id):string{return Security::generateToken(['uid'=>$id,'sub_type'=>'password_reset','purpose'=>'change_account_password','av'=>(int)caVal('SELECT auth_version FROM users WHERE id=?',[$id]),'email_hash'=>Security::requestFingerprint((string)caVal('SELECT email FROM users WHERE id=?',[$id])),'jti'=>caUuid()],900);}
$adminAuth=new AdminAuthService();$aid=$adminAuth->create(['username'=>'customer-accounts-super','email'=>'customer-accounts-super@example.test','password'=>'CustomerAdmin!2026','role'=>'super_admin']);$adminToken=caToken($aid);
$auth=new AuthService();$session=$auth->register(['email'=>'customer-accounts@example.test','password'=>'CustomerOriginal!2026']);$user=(int)$session['user']['id'];$token=$session['token'];$path='/admin/customers/'.$user;
foreach([null,$token] as $t){caCheck(caHttp($path.'/account',$t)[0]===401&&caHttp($path.'/status',$t,'PATCH',[])[0]===401&&caHttp($path.'/password',$t,'POST',[])[0]===401,'anonymous/client scope cannot manage customer accounts');}
foreach(['finance','support','operator'] as $role){$id=$adminAuth->create(['username'=>'customer-accounts-'.$role,'email'=>'customer-accounts-'.$role.'@example.test','password'=>'CustomerAdmin!2026','role'=>$role]);$t=caToken($id);caCheck(caHttp($path,$t)[0]===200,'existing customer query retained for '.$role);caCheck(caHttp($path.'/account',$t)[0]===403&&caHttp($path.'/status',$t,'PATCH',[])[0]===403&&caHttp($path.'/password',$t,'POST',[])[0]===403,$role.' cannot read operation audit or mutate');}
[$code,$detail,$cache]=caHttp($path.'/account',$adminToken);caCheck($code===200&&str_contains($cache,'no-store')&&count($detail['data']['account'])===4,'super receives safe noncacheable account version');
caCheck(caHttp('/admin/customers/999999999/account',$adminToken)[0]===404,'missing customer reports 404');
caCheck(caHttp($path.'/account?request_key[]=x',$adminToken)[0]===400,'malformed operation lookup rejected');
foreach([['confirmed'=>false],['status'=>'disabled'],['evidence'=>'   '],['expected_version'=>'bad'],['request_key'=>'not-uuid'],['balance'=>'100']] as $bad){$body=array_replace(caBody($user),$bad);caCheck(caHttp($path.'/status',$adminToken,'PATCH',$body)[0]===400,'invalid or unsupported account change rejected');}
caReject(fn()=>$service->change($aid,caSession($aid),$user,caBody($user,'active')),409,'empty status change cannot revoke sessions');
$demo=new DemoService();$demo->recharge($user,'2.00','customer-accounts');$stable=caGet($user)['account']['version'];$demo->recharge($user,'0.01','customer-accounts-extra');caCheck(caGet($user)['account']['version']===$stable,'balance receipts do not stale account version');
// All upstream operations below target the local fixture only.
file_get_contents(getenv('UPSTREAM_TEST_URL').'/__mode?mode=normal');file_get_contents(getenv('UPSTREAM_TEST_URL').'/__service_mode?mode=normal');
$db->exec("INSERT INTO providers(name,driver,endpoint,credentials) SELECT 'customer-account-fixture',driver,endpoint,credentials FROM providers WHERE name='renewal-fixture' LIMIT 1");$provider=(int)$db->lastInsertId();
$product=(new ProductSnapshotService())->save(['provider_id'=>$provider,'remote_pid'=>391,'name'=>'Customer account CDN fixture','type'=>'cdn','billing_cycles'=>['monthly']])['product_id'];
$db->prepare("UPDATE products SET status='active' WHERE id=?")->execute([$product]);
$billing=new BillingService();$worker=new OutboxWorker();
$order=static fn(bool $pay)=>$billing->checkout($user,['product_id'=>$product,'hostname'=>'AccountsFixture','password'=>'AccountsRoot12','billing_cycle'=>'monthly','use_balance'=>$pay]);
$first=$order(true);$worker->runOnce('customer-accounts-initial');$iid=(int)$first['instance_id'];caCheck(caVal('SELECT status FROM instances WHERE id=?',[$iid])==='active','fixture resource delivered');
$db->prepare('UPDATE instances SET due_date=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 2 DAY),auto_renew=1 WHERE id=?')->execute([$iid]);$renew=(new RenewalService())->issue($user,$iid);$rid=(int)$renew['invoice_id'];
$paid=$order(true);$unpaid=$order(false);
$settingsRow=PaymentSettingsService::row($db,'epay');$config=json_decode($settingsRow['value'],true);$config['enabled']=true;$db->prepare("UPDATE system_settings SET value=? WHERE `key`='payment.epay'")->execute([json_encode($config)]);$secret=json_decode(Security::decrypt($settingsRow['encrypted_credentials']),true)['key'];
function caNotice(int $invoice,string $label):array{global $user,$config,$secret;$fields=(new PaymentService())->checkout($user,$invoice,'epay')['checkout']['fields'];$v=['pid'=>$config['pid'],'out_trade_no'=>$fields['out_trade_no'],'trade_no'=>'customer-accounts-'.$label,'money'=>$fields['money'],'trade_status'=>'TRADE_SUCCESS','sign_type'=>'MD5'];$v['sign']=\Plugins\payments\epay\EpayDriver::sign($v,$secret);return $v;}
$cloudNotice=caNotice((int)$unpaid['invoice_id'],'cloud');$recharge=(new WalletService())->recharge($user,['amount'=>'0.01','request_key'=>'customer-accounts-recharge']);$rechargeNotice=caNotice((int)$recharge['invoice_id'],'recharge');$late=(new WalletService())->recharge($user,['amount'=>'0.01','request_key'=>'customer-accounts-cancelled']);$lateNotice=caNotice((int)$late['invoice_id'],'late');$billing->cancel($user,(int)$late['invoice_id']);
$resourceBefore=caVal('SELECT JSON_OBJECT("status",status,"remote",remote_instance_id,"due",due_date,"auto",auto_renew) FROM instances WHERE id=?',[$iid]);
$oldReset=caResetToken($user);$disable=caBody($user);caCheck(caHttp($path.'/status',$adminToken,'PATCH',$disable)[0]===200,'super disables with version and evidence');
caCheck(caHttp('/auth/me',$token)[0]===403,'disable immediately rejects old login');
caReject(fn()=>$auth->login(['email'=>'customer-accounts@example.test','password'=>'CustomerOriginal!2026']),403,'suspended password login refused');
foreach([['POST','/wallet/recharges'],['POST','/orders/checkout'],['POST','/invoices/'.$rid.'/pay'],['POST','/instances/'.$iid.'/power'],['POST','/instances/'.$iid.'/reinstall'],['PATCH','/instances/'.$iid.'/auto-renew'],['POST','/tickets']] as [$method,$endpoint]){caCheck(caHttp($endpoint,$token,$method,[])[0]===403,'suspended customer cannot actively call '.$endpoint);}
caReject(fn()=>$billing->pay($user,$rid,true),403,'automatic debit refuses suspended customer');caCheck((new RenewalService())->debitDue($user)===0&&caVal('SELECT status FROM invoices WHERE id=?',[$rid])==='unpaid','scheduled/wake-up debit skips without changing original renewal invoice');
$worker->runOnce('customer-accounts-suspended-delivery');caCheck(caVal('SELECT status FROM instances WHERE id=?',[$paid['instance_id']])==='active','already paid task still delivers for suspended customer');
foreach([$rechargeNotice,$lateNotice,$cloudNotice] as $n){caCheck(caHttp('/payments/epay/notify',null,'POST',$n)[0]===200,'old receipt accepted while customer suspended');caCheck(caHttp('/payments/epay/notify',null,'POST',$n)[1]==='success','duplicate old receipt acknowledged');}
caCheck(caVal('SELECT status FROM invoices WHERE id=?',[$recharge['invoice_id']])==='paid'&&(int)caVal('SELECT COUNT(*) FROM transactions WHERE invoice_id=?',[$recharge['invoice_id']])===1,'suspended recharge credited once');
caCheck(caVal('SELECT status FROM invoices WHERE id=?',[$late['invoice_id']])==='cancelled'&&caVal('SELECT type FROM transactions WHERE invoice_id=?',[$late['invoice_id']])==='late_payment_credit','cancelled late payment preserved in wallet');
caCheck(caVal('SELECT status FROM invoices WHERE id=?',[$unpaid['invoice_id']])==='paid'&&(int)caVal('SELECT COUNT(*) FROM tasks WHERE invoice_id=?',[$unpaid['invoice_id']])===1,'old cloud receipt still settles with one delivery task');
$worker->runOnce('customer-accounts-old-receipt-delivery');caCheck(caVal('SELECT status FROM instances WHERE id=?',[$unpaid['instance_id']])==='active','old receipt delivery is not blocked by suspended owner');
caCheck(caVal('SELECT JSON_OBJECT("status",status,"remote",remote_instance_id,"due",due_date,"auto",auto_renew) FROM instances WHERE id=?',[$iid])===$resourceBefore,'suspension leaves resource status binding period and auto-renew untouched');
caChange($user,'active');caCheck(caHttp('/auth/me',$token)[0]===401,'reenable cannot revive previous token');caReject(fn()=>(new PasswordService())->reset(['token'=>$oldReset,'new_password'=>'ForbiddenReset!2026']),400,'reenable cannot revive previous reset link');caCheck(caGet($user)['account']['version']!==$disable['expected_version'],'status ABA cannot revive old preview');
caReject(fn()=>$service->change($aid,caSession($aid),$user,$disable),409,'old disable version rejected after enable');
$current=$auth->login(['email'=>'customer-accounts@example.test','password'=>'CustomerOriginal!2026'])['token'];$resetLink=caResetToken($user);$reset=caBody($user);unset($reset['status']);$reset['new_password']='CustomerReplacement!2026';
foreach(['short',str_repeat('x',73),"NulPassword\0Test"] as $bad){caReject(fn()=>$service->change($aid,caSession($aid),$user,array_replace($reset,['new_password'=>$bad]),true),400,'invalid admin reset password rejected');}
caCheck(caHttp($path.'/password',$adminToken,'POST',$reset)[0]===200,'super explicitly resets customer password');caCheck(caHttp('/auth/me',$current)[0]===401,'reset revokes customer session');caReject(fn()=>(new PasswordService())->reset(['token'=>$resetLink,'new_password'=>'ForbiddenReset!2026']),400,'reset revokes preexisting recovery link');
caReject(fn()=>$auth->login(['email'=>'customer-accounts@example.test','password'=>'CustomerOriginal!2026']),401,'old password no longer authenticates');caCheck(isset($auth->login(['email'=>'customer-accounts@example.test','password'=>$reset['new_password']])['token']),'new password authenticates');caReject(fn()=>$service->change($aid,caSession($aid),$user,$reset,true),409,'duplicate reset cannot advance version twice');
$record=$service->detail($aid,caSession($aid),$user,$reset['request_key']);caCheck($record['operation']['context']['request_key']===$reset['request_key'],'read-only lookup proves original reset audit');
$encoded=json_encode($record);foreach([$reset['new_password'],(string)caVal('SELECT password FROM users WHERE id=?',[$user]),'auth_version','encrypted_password','balance'] as $hidden){caCheck(!str_contains($encoded,$hidden),'account projection and audit omit '.$hidden);}
// Reset and status edits share one authentication version, including concurrent reset attempts.
$resetRace=caBody($user);unset($resetRace['status']);$resetRace['new_password']='CustomerReplacement!2026';
$child=Database::transaction(function(PDO $db)use($aid,$user,$resetRace):array{global $service;$service->change($aid,caSession($aid),$user,$resetRace,true);$c=caSpawn(['op'=>'change','actor'=>$aid,'session'=>caSession($aid),'user'=>$user,'body'=>$resetRace,'password'=>true]);caWaiting($c);return $c;});
caCheck(caFinish($child)['status']===409,'real concurrent password reset accepts one preview once');
$usedKey=caBody($user);$usedKey['request_key']=$reset['request_key'];caReject(fn()=>$service->change($aid,caSession($aid),$user,$usedKey),409,'operation key cannot be reused with a new preview');
// Audit failure rolls back password, status and revocation together.
$before=caGet($user)['account'];$hash=caVal('SELECT password FROM users WHERE id=?',[$user]);$db->exec("CREATE TRIGGER customer_accounts_audit_failure BEFORE INSERT ON admin_audit_logs FOR EACH ROW BEGIN IF NEW.action IN ('customer_status_update','customer_password_reset') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='CUSTOMER_ACCOUNT_AUDIT_FAILURE'; END IF; END");
try{foreach([false,true] as $password){$v=caBody($user);if($password){unset($v['status']);$v['new_password']='RollbackCustomer!2026';}try{$service->change($aid,caSession($aid),$user,$v,$password);throw new RuntimeException('Expected audit failure');}catch(PDOException $e){caCheck(str_contains($e->getMessage(),'CUSTOMER_ACCOUNT_AUDIT_FAILURE'),'audit failure propagates');}}}finally{$db->exec('DROP TRIGGER customer_accounts_audit_failure');}
caCheck(caGet($user)['account']===$before&&caVal('SELECT password FROM users WHERE id=?',[$user])===$hash,'failed audit leaves original password status and auth version intact');
// Two administrators acting on the same preview cannot overwrite each other.
$bid=$adminAuth->create(['username'=>'customer-accounts-second','email'=>'customer-accounts-second@example.test','password'=>'CustomerAdmin!2026','role'=>'super_admin']);$body=caBody($user);
$child=Database::transaction(function(PDO $db)use($aid,$bid,$user,$body):array{global $service;$service->change($aid,caSession($aid),$user,$body);$c=caSpawn(['op'=>'change','actor'=>$bid,'session'=>caSession($bid),'user'=>$user,'body'=>$body]);caWaiting($c);return $c;});
caCheck(caFinish($child)['status']===409,'real concurrent account change rejects stale preview');caChange($user,'active');
// Disable-first: waiting automatic debit must recheck current status, not its earlier snapshot.
$body=caBody($user);$balance=caVal('SELECT balance FROM users WHERE id=?',[$user]);
$child=Database::transaction(function(PDO $db)use($aid,$user,$rid,$body):array{global $service;$s=$db->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$s->execute([$user]);$c=caSpawn(['op'=>'pay','user'=>$user,'invoice'=>$rid]);caWaiting($c);$service->change($aid,caSession($aid),$user,$body);return $c;});
caCheck(caFinish($child)['status']===403&&caVal('SELECT balance FROM users WHERE id=?',[$user])===$balance&&caVal('SELECT status FROM invoices WHERE id=?',[$rid])==='unpaid','disable-first debit rolls back invoice CAS and money');caChange($user,'active');
// A preauthorized HTTP request cannot cross a disable/reenable ABA while waiting on the account.
$fresh=$auth->login(['email'=>'customer-accounts@example.test','password'=>'CustomerReplacement!2026'])['token'];
$child=Database::transaction(function(PDO $db)use($user,$fresh):array{$s=$db->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$s->execute([$user]);$c=caSpawn(['op'=>'recharge','user'=>$user,'token'=>$fresh]);caWaiting($c);caChange($user,'suspended');caChange($user,'active');return $c;});
caCheck(caFinish($child)['status']===401&&(int)caVal("SELECT COUNT(*) FROM invoices WHERE user_id=? AND checkout_key='recharge:accounts-waiting-recharge'",[$user])===0,'waiting old request cannot cross suspend-enable session revocation');
// Debit-first: the admitted payment commits once and its paid task survives subsequent disable.
$child=Database::transaction(function(PDO $db)use($user,$rid,$aid):array{(new BillingService())->pay($user,$rid,true);$c=caSpawn(['op'=>'change','actor'=>$aid,'session'=>caSession($aid),'user'=>$user,'body'=>caBody($user)]);caWaiting($c);return $c;});
caCheck(caFinish($child)['status']===200&&caVal('SELECT status FROM invoices WHERE id=?',[$rid])==='paid'&&(int)caVal('SELECT COUNT(*) FROM tasks WHERE invoice_id=?',[$rid])===1,'debit-first commits exactly one task before disable');
$worker->runOnce('customer-accounts-paid-renew');caCheck(caVal('SELECT status FROM tasks WHERE invoice_id=?',[$rid])==='success','paid renewal completes for suspended account');
caCheck((int)caVal("SELECT COUNT(*) FROM transactions WHERE invoice_id=? AND type='pay_invoice'",[$rid])===1,'renewal wallet debit remains unique');
// A queued operation cannot use an admin session revoked before acquiring authorization locks.
$body=caBody($user,'active');$child=Database::transaction(function(PDO $db)use($bid,$aid,$user,$body):array{$s=$db->prepare('SELECT id FROM admins WHERE id=? FOR UPDATE');$s->execute([$bid]);$c=caSpawn(['op'=>'change','actor'=>$bid,'session'=>caSession($bid),'user'=>$user,'body'=>$body]);caWaiting($c);(new AdminAccountService())->update($aid,caSession($aid),$bid,['role'=>'support','status'=>'active','expected_version'=>(new AdminAccountService())->detail($aid,caSession($aid),$bid)['account']['version'],'evidence'=>'CUSTOMER ACCOUNT: revoke waiting actor']);return $c;});
caCheck(caFinish($child)['status']===401&&caVal('SELECT status FROM users WHERE id=?',[$user])==='suspended','revoked waiting administrator cannot enable customer');
// Query by operation key is not limited to the 20 most recent audit rows.
$facts=[];foreach(['invoices','payment_records','transactions','instances','tasks'] as $table){$facts[$table]=$db->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll();}
for($n=0;$n<22;$n++){caChange($user,$n%2===0?'active':'suspended');}
$record=$service->detail($aid,caSession($aid),$user,$reset['request_key']);caCheck(count($record['audit'])===20&&$record['operation']!==null,'old operation still discoverable beyond recent audit window');
foreach($facts as $table=>$rows){caCheck($db->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll()===$rows,'account-only operations leave '.$table.' facts unchanged');}
caCheck(caHttp('/admin/customers/'.$user,$adminToken)[0]===200,'customer read-only query remains available after account operations');
echo 'Customer account checks: '.$checks.PHP_EOL;
