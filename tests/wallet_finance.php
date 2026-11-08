<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
set_exception_handler(static function(Throwable $e):never{fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
use App\Core\{Database,Security};
use App\Services\{AuthService,AdminAuthService,BillingService,WalletService,PaymentService,FinanceQueryService,OutboxWorker,RenewalService};
if(config('app.env')!=='test'||config('database.connections.mysql.database')!=='jianyun_test'){throw new RuntimeException('Isolated DB required');}
$db=Database::getInstance();$checks=0;
function wfCheck(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException('FAIL wallet: '.$why);$checks++;echo "PASS wallet: $why\n";}
function wfValue(string $sql,array $args=[]):mixed{global $db;$s=$db->prepare($sql);$s->execute($args);return $s->fetchColumn();}
function wfHandle(string $method,string $path,?array $body=null,?string $token=null):CurlHandle{
 $c=curl_init(getenv('TEST_BASE_URL').'/api/v1'.$path);curl_setopt_array($c,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>array_values(array_filter(['Content-Type: application/json',$token?'Authorization: Bearer '.$token:null]))]);if($body!==null)curl_setopt($c,CURLOPT_POSTFIELDS,json_encode($body===[]?(object)[]:$body,JSON_THROW_ON_ERROR));return $c;
}
function wfHttp(string $method,string $path,?array $body=null,?string $token=null):array{$c=wfHandle($method,$path,$body,$token);$raw=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);return [$status,json_decode($raw,true,64,JSON_THROW_ON_ERROR)];}
function wfParallel(array $handles):array{$multi=curl_multi_init();foreach($handles as $c)curl_multi_add_handle($multi,$c);do{curl_multi_exec($multi,$running);if($running)curl_multi_select($multi,1);}while($running);$results=[];foreach($handles as $c){$results[]=[curl_getinfo($c,CURLINFO_RESPONSE_CODE),curl_multi_getcontent($c)];curl_multi_remove_handle($multi,$c);curl_close($c);}curl_multi_close($multi);return $results;}
function wfNotice(array $form,string $trade,?string $amount=null):array{$p=['pid'=>'wallet-fixture','out_trade_no'=>$form['fields']['out_trade_no'],'trade_no'=>$trade,'money'=>$amount??$form['fields']['money'],'trade_status'=>'TRADE_SUCCESS','sign_type'=>'MD5'];$p['sign']=\Plugins\payments\epay\EpayDriver::sign($p,'wallet-fixture-key');return $p;}
function wfCallback(array $data,string $method='GET'):CurlHandle{$c=curl_init(getenv('TEST_BASE_URL').'/api/v1/payments/epay/notify'.($method==='GET'?'?'.http_build_query($data):''));curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30]);if($method==='POST')curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($data)]);return $c;}
function wfNotify(array $data):array{return wfParallel([wfCallback($data)])[0];}
$auth=new AuthService();$session=$auth->register(['email'=>'wallet-customer@example.test','password'=>'WalletCustomer!2026']);$uid=(int)$session['user']['id'];$token=$session['token'];
$other=$auth->register(['email'=>'wallet-other@example.test','password'=>'WalletOther!2026']);$wallet=new WalletService();$pay=new PaymentService();$billing=new BillingService();
$admins=[];foreach(['super_admin','finance','support','operator'] as $role){$id=(new AdminAuthService())->create(['username'=>'wallet-'.$role,'email'=>'wallet-'.$role.'@example.test','password'=>'WalletAdmin!2026','role'=>$role]);$admins[$role]=['id'=>$id,'token'=>Security::generateToken(['uid'=>$id,'sub_type'=>'admin'])];}
$config=['enabled'=>true,'pid'=>'wallet-fixture','endpoint'=>getenv('TEST_BASE_URL'),'notify_url'=>getenv('TEST_BASE_URL').'/api/v1/payments/epay/notify','return_url'=>'http://127.0.0.1'];
$db->prepare("INSERT INTO system_settings(`key`,value,encrypted_credentials) VALUES('payment.epay',?,?) ON DUPLICATE KEY UPDATE value=VALUES(value),encrypted_credentials=VALUES(encrypted_credentials)")->execute([json_encode($config),Security::encrypt(json_encode(['key'=>'wallet-fixture-key']))]);
wfCheck(wfHttp('GET','/wallet')[0]===401,'wallet requires customer login');
wfCheck(wfHttp('GET','/wallet',null,$admins['finance']['token'])[0]===401,'admin token is not a customer wallet token');
wfCheck(wfHttp('GET','/wallet',null,$token)[1]['data']['balance']==='0.00','new wallet exposes exact decimal balance');
foreach([0,1.25,'0','-1','0.001','1e2','100000000.00','NaN',[]] as $amount){wfCheck(wfHttp('POST','/wallet/recharges',['amount'=>$amount,'request_key'=>'wallet-invalid-amount'],$token)[0]===400,'reject invalid or floating recharge amount: '.json_encode($amount));}
wfCheck(wfHttp('POST','/wallet/recharges',['amount'=>'1.00','request_key'=>'short'],$token)[0]===400,'recharge key is mandatory and bounded');
$body=['amount'=>'100','request_key'=>'wallet-concurrent-create'];$handles=[];for($x=0;$x<8;$x++)$handles[]=wfHandle('POST','/wallet/recharges',$body,$token);
$ids=[];foreach(wfParallel($handles) as [$status,$raw]){wfCheck($status===200,'concurrent recharge creation acknowledged');$ids[]=json_decode($raw,true)['data']['invoice_id'];}
wfCheck(count(array_unique($ids))===1,'concurrent request creates exactly one recharge invoice');$inv=(int)$ids[0];
$original=$billing->invoice($uid,$inv);wfCheck($original['total']==='100.00'&&$original['instance_id']===null&&$original['quote_expired_at']===null,'recharge has no instance or cloud quote expiry');
wfCheck($wallet->recharge($uid,['amount'=>'100.00','request_key'=>$body['request_key']])['invoice_id']===$inv,'normalized same-amount replay returns original invoice');
wfCheck(wfHttp('POST','/wallet/recharges',['amount'=>'101','request_key'=>$body['request_key']],$token)[0]===409,'same recharge key cannot change amount');
wfCheck($wallet->recharge((int)$other['user']['id'],$body)['invoice_id']!==$inv,'recharge keys are scoped to customer');
wfCheck(wfHttp('POST',"/invoices/$inv/pay",['gateway'=>'balance'],$token)[0]===400,'balance cannot fund its own recharge');
wfCheck(wfHttp('POST',"/invoices/$inv/pay",['gateway'=>'epay'],$other['token'])[0]===403,'foreign customer cannot create cashier attempt');
$form=wfHttp('POST',"/invoices/$inv/pay",['gateway'=>'epay'],$token)[1]['data']['checkout'];
wfCheck(!str_contains(json_encode($form),'wallet-fixture-key')&&$form['fields']['money']==='100.00','original Epay form contains signed exact amount without secret');
$n=wfNotice($form,'wallet-main');$bad=$n;$bad['money']='0.01';wfCheck(wfNotify($bad)===[400,'fail'],'tampered payment cannot credit wallet');
$before=['instances'=>(int)$db->query('SELECT COUNT(*) FROM instances')->fetchColumn(),'tasks'=>(int)$db->query('SELECT COUNT(*) FROM tasks')->fetchColumn(),'commissions'=>(int)$db->query('SELECT COUNT(*) FROM commissions')->fetchColumn()];
$handles=[];for($x=0;$x<8;$x++)$handles[]=wfCallback($n,$x%2?'POST':'GET');
foreach(wfParallel($handles) as $result)wfCheck($result===[200,'success'],'concurrent GET/POST duplicate receipt acknowledged');
wfCheck($wallet->summary($uid)['balance']==='100.00'&&$billing->invoice($uid,$inv)['status']==='paid','verified recharge credits wallet and pays invoice once');
wfCheck((int)wfValue('SELECT COUNT(*) FROM transactions WHERE invoice_id=?',[$inv])===1&&(int)wfValue('SELECT COUNT(*) FROM payment_records WHERE invoice_id=? AND gateway_trade_no IS NOT NULL',[$inv])===1,'duplicate receipt produces one receipt and one ledger entry');
wfCheck(wfValue("SELECT JSON_UNQUOTE(JSON_EXTRACT(payload,'$.settlement_purpose')) FROM payment_records WHERE invoice_id=?",[$inv])==='wallet_recharge','normal recharge purpose is distinct from cloud consumption');
wfCheck(wfValue('SELECT total_spent FROM users WHERE id=?',[$uid])==='0.00','recharge does not count consumption');
foreach($before as $table=>$count)wfCheck((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===$count,'recharge does not create '.$table);
wfCheck(wfHttp('POST',"/invoices/$inv/cancel",[],$token)[0]===409,'paid recharge cannot be cancelled');
$extra=wfNotice($form,'wallet-extra','7.00');wfNotify($extra);wfNotify($extra);
wfCheck($wallet->summary($uid)['balance']==='107.00'&&$billing->invoice($uid,$inv)['status']==='paid','independent extra receipt credits exactly once and preserves paid parent');
$cancel=$wallet->recharge($uid,['amount'=>'5.00','request_key'=>'wallet-cancelled-charge']);$ci=(int)$cancel['invoice_id'];$cf=$pay->checkout($uid,$ci,'epay')['checkout'];
$handles=[];for($x=0;$x<8;$x++)$handles[]=wfHandle('POST',"/invoices/$ci/cancel",[],$token);
foreach(wfParallel($handles) as [$status,$raw])wfCheck($status===200&&json_decode($raw,true)['data']['status']==='cancelled','concurrent recharge cancellation returns latest cancelled state');
wfCheck(wfHttp('POST',"/invoices/$ci/pay",['gateway'=>'epay'],$token)[0]===409,'cancelled recharge refuses new cashier');
wfNotify(wfNotice($cf,'wallet-cancelled'));wfNotify(wfNotice($cf,'wallet-cancelled'));
wfCheck($wallet->summary($uid)['balance']==='112.00'&&$billing->invoice($uid,$ci)['status']==='cancelled','cancelled old cashier receipt credits once without changing cancellation');
$mismatch=$wallet->recharge($uid,['amount'=>'8','request_key'=>'wallet-mismatched-charge']);$mi=(int)$mismatch['invoice_id'];$mf=$pay->checkout($uid,$mi,'epay')['checkout'];wfNotify(wfNotice($mf,'wallet-small','0.25'));wfNotify(wfNotice($mf,'wallet-small','0.25'));
wfCheck($wallet->summary($uid)['balance']==='112.25'&&$billing->invoice($uid,$mi)['status']==='unpaid','verified amount mismatch preserves real funds and leaves intended invoice unpaid');
wfNotify(wfNotice($mf,'wallet-correct','8.00'));wfCheck($wallet->summary($uid)['balance']==='120.25'&&$billing->invoice($uid,$mi)['status']==='paid','later distinct correct receipt pays the same original invoice');
// Force a ledger insert error AFTER receipt/CAS/wallet writes; every financial effect must roll back.
$failure=$wallet->recharge($uid,['amount'=>'1','request_key'=>'wallet-atomic-failure']);$fi=(int)$failure['invoice_id'];$ff=$pay->checkout($uid,$fi,'epay')['checkout'];$fn=wfNotice($ff,'wallet-rollback');
$db->exec("CREATE TRIGGER wallet_finance_test_fail BEFORE INSERT ON transactions FOR EACH ROW BEGIN IF NEW.invoice_id=$fi THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated wallet fault'; END IF; END");
try{wfCheck(wfNotify($fn)===[500,'fail'],'ledger failure is not acknowledged as payment success');wfCheck($wallet->summary($uid)['balance']==='120.25'&&$billing->invoice($uid,$fi)['status']==='unpaid'&&wfValue('SELECT gateway_trade_no FROM payment_records WHERE invoice_id=?',[$fi])===null,'ledger failure rolls back receipt, invoice CAS and wallet together');}finally{$db->exec('DROP TRIGGER wallet_finance_test_fail');}
wfCheck(wfNotify($fn)===[200,'success']&&$wallet->summary($uid)['balance']==='121.25','retry after repair settles original receipt exactly once');
// Verified incoming money remains attributable even when the customer was suspended after checkout.
$disabled=$wallet->recharge($uid,['amount'=>'1','request_key'=>'wallet-disabled-incoming']);$df=$pay->checkout($uid,(int)$disabled['invoice_id'],'epay')['checkout'];$db->prepare("UPDATE users SET status='suspended' WHERE id=?")->execute([$uid]);
wfCheck(wfNotify(wfNotice($df,'wallet-suspended'))===[200,'success']&&$wallet->summary($uid)['balance']==='122.25','suspended customer still receives verified incoming money');
wfCheck(wfHttp('GET','/wallet',null,$token)[0]===403,'suspended customer still cannot access wallet');$db->prepare("UPDATE users SET status='active' WHERE id=?")->execute([$uid]);
// Spend this same funded wallet through a real isolated provider protocol and normal Outbox.
file_get_contents(getenv('UPSTREAM_TEST_URL').'/__mode?mode=normal');$db->exec("UPDATE tasks SET status='success',lease_until=NULL WHERE status IN ('pending','running','investigating')");
$product=(int)wfValue("SELECT p.id FROM products p JOIN providers r ON r.id=p.provider_id WHERE r.name='renewal-fixture' AND p.remote_pid=391 LIMIT 1");
$order=$billing->checkout($uid,['product_id'=>$product,'hostname'=>'wallet-funded','password'=>'WalletRootPass12','billing_cycle'=>'monthly','use_balance'=>true]);$oi=(int)$order['invoice_id'];
$worker=new OutboxWorker();$worker->runOnce('wallet-funded-create');
wfCheck(wfValue('SELECT status FROM instances WHERE id=?',[$order['instance_id']])==='active','recharged wallet purchases and isolated Worker delivers resource');
$renew=(new RenewalService())->issue($uid,(int)$order['instance_id']);$ri=(int)$renew['invoice_id'];$billing->pay($uid,$ri);$billing->pay($uid,$ri);$worker->runOnce('wallet-funded-renew');
wfCheck(wfValue('SELECT status FROM tasks WHERE invoice_id=?',[$ri])==='success'&&(int)wfValue('SELECT COUNT(*) FROM transactions WHERE invoice_id=?',[$ri])===1,'recharged wallet renews via one debit and one fulfilled renew task');
wfCheck($wallet->summary($uid)['balance']==='122.23'&&wfValue('SELECT total_spent FROM users WHERE id=?',[$uid])==='0.02','only balance purchase and renewal count consumption and debit funded money');
// Finance projections and boundaries.
$finance=$admins['finance']['token'];$base='/admin/finance/';
wfCheck(wfHttp('GET',$base.'invoices',null,$token)[0]===401,'customer token cannot query global finances');
foreach(['support','operator'] as $role)wfCheck(wfHttp('GET',$base.'invoices',null,$admins[$role]['token'])[0]===403,'nonfinancial role cannot read global funds: '.$role);
wfCheck(wfHttp('GET',$base.'invoices',null,$admins['super_admin']['token'])[0]===200,'super admin can read finances');
$receipt=wfHttp('GET',$base.'receipts?gateway_trade_no=wallet-extra',null,$finance)[1]['data'];wfCheck($receipt['total']===1&&$receipt['items'][0]['settlement_purpose']==='late_wallet_credit','finance gateway filter identifies extra real receipt purpose');
$ledger=wfHttp('GET',$base.'transactions?gateway_trade_no=wallet-extra',null,$finance)[1]['data'];wfCheck($ledger['total']===1&&$ledger['items'][0]['amount']==='7.00'&&$ledger['items'][0]['payment_record_id']===$receipt['items'][0]['id'],'gateway filter links only the exact extra receipt wallet credit');
wfCheck(wfHttp('GET',$base.'invoices?gateway_trade_no=wallet-extra',null,$finance)[1]['data']['items'][0]['invoice_id']===$inv,'gateway filter finds parent invoice without changing it');
$detail=wfHttp('GET',$base.'invoices/'.$inv,null,$finance)[1]['data'];wfCheck($detail['invoice']['status']==='paid'&&$detail['receipts']['total']===2&&$detail['transactions']['total']===2&&$detail['customer']['balance']==='122.23','invoice detail joins original bill, all distinct receipts, wallet flows and current customer balance');
wfCheck(!preg_match('/checkout_hash|idempotency_key|price_snapshot|payload|password|wallet-fixture-key/',json_encode($detail)),'finance response exposes no arbitrary JSON or credentials');
wfCheck(wfHttp('GET',$base.'transactions?invoice_id='.$ri,null,$finance)[1]['data']['items'][0]['payment_record_id']!==null,'historical balance ledger links through its original stable key');
$mine=wfHttp('GET','/wallet/transactions?user_id='.$other['user']['id'],null,$token)[1]['data'];wfCheck($mine['total']===9&&count(array_unique(array_column($mine['items'],'user_id')))===1&&(int)$mine['items'][0]['user_id']===$uid,'customer cannot switch ledger ownership through query parameters');
wfCheck(wfHttp('GET','/wallet/transactions?invoice_id='.$inv,null,$other['token'])[1]['data']['total']===0,'foreign invoice filter does not expose wallet records');
$p1=wfHttp('GET','/wallet/transactions?per_page=1&page=1',null,$token)[1]['data'];$p2=wfHttp('GET','/wallet/transactions?per_page=1&page=2',null,$token)[1]['data'];wfCheck($p1['items'][0]['id']!==$p2['items'][0]['id'],'wallet ledger paginates without duplicate rows');
$filtered=wfHttp('GET',$base.'invoices?email=wallet-customer%40example.test',null,$finance)[1]['data'];
wfCheck($filtered['total']===7 && array_unique(array_column($filtered['items'],'user_id'))===[$uid],'customer email filter uses original invoice ownership');
wfCheck(wfHttp('GET',$base.'receipts?user_id='.$uid.'&from=2000-01-01%2000:00:00&to=2099-12-31%2023:59:59',null,$finance)[1]['data']['total']>=8,'UTC date range includes payments for selected customer');
wfCheck(wfHttp('GET',$base.'receipts?from=2099-01-01%2000:00:00',null,$finance)[1]['data']['total']===0,'future time range returns an empty result');
foreach(['page=0','per_page=101','user_id[]=1','invoice_id=0','from=2026-02-31%2000:00:00','from=2099-01-01%2000:00:00&to=2000-01-01%2000:00:00','gateway_trade_no[]=x'] as $q)wfCheck(wfHttp('GET',$base.'receipts?'.$q,null,$finance)[0]===400,'invalid finance query rejected: '.$q);
wfCheck(wfHttp('GET',$base.'invoices/999999999',null,$finance)[0]===404,'missing invoice detail returns 404');
$db->prepare("UPDATE admins SET role='support' WHERE id=?")->execute([$admins['finance']['id']]);wfCheck(wfHttp('GET',$base.'invoices',null,$finance)[0]===403,'revoked finance role is rechecked against current database');
// Even a valid gateway signature cannot move an existing receipt to another account.
$otherId=(int)$other['user']['id'];$otherBill=$wallet->recharge($otherId,$body);$otherInvoice=(int)$otherBill['invoice_id'];
$otherForm=$pay->checkout($otherId,$otherInvoice,'epay')['checkout'];
wfCheck(wfNotify(wfNotice($otherForm,'wallet-main'))===[400,'fail'] && $wallet->summary($otherId)['balance']==='0.00','gateway receipt identity cannot be reused across customer accounts');
// Saturate only this disposable test wallet; an unrepresentable credit must not be acknowledged.
$db->prepare('UPDATE users SET balance=? WHERE id=?')->execute(['99999999.99',$otherId]);
$overflow=wfNotice($otherForm,'wallet-capacity');
wfCheck(wfNotify($overflow)===[400,'fail'],'wallet capacity failure requests gateway retry instead of dropping money');
wfCheck($billing->invoice($otherId,$otherInvoice)['status']==='unpaid' && wfValue('SELECT gateway_trade_no FROM payment_records WHERE invoice_id=?',[$otherInvoice])===null && (int)wfValue('SELECT COUNT(*) FROM transactions WHERE invoice_id=?',[$otherInvoice])===0,'capacity failure rolls back invoice, receipt and ledger');
$db->prepare('UPDATE users SET balance=? WHERE id=?')->execute(['0.00',$otherId]);
wfCheck(wfNotify($overflow)===[200,'success'] && wfNotify($overflow)===[200,'success'] && $wallet->summary($otherId)['balance']==='100.00','original incoming payment settles once after capacity is restored');
echo "Wallet finance checks: $checks\n";
