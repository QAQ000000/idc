<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
set_exception_handler(static function(Throwable $e):never { fwrite(STDERR, 'Deployment fixture failed: '.$e->getMessage()."\n"); exit(1); });
use App\Core\{Database,RedisStore,Security};
if (getenv('DEPLOYMENT_REHEARSAL')!=='1' || config('app.env')!=='production'
    || config('database.connections.mysql.database')!=='jianyun_release_test'
    || !str_starts_with(base_path(),sys_get_temp_dir().'/jianyun-test-')) { throw new RuntimeException('Disposable release required'); }
$db=Database::getInstance();$checks=0;
function releaseCheck(bool $v,string $m):void { global $checks; if(!$v){throw new RuntimeException($m);} $checks++; echo "PASS deployment: $m\n"; }
function releaseHttp(string $method,string $path,?array $input=null,?string $token=null):array {
    $c=curl_init(getenv('RELEASE_URL').'/api/v1'.$path);
    curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_TIMEOUT=>15,
        CURLOPT_CAINFO=>getenv('RELEASE_CERT'),CURLOPT_HTTPHEADER=>array_values(array_filter(['Content-Type: application/json',$token?'Authorization: Bearer '.$token:null]))]);
    if($input!==null){curl_setopt($c,CURLOPT_POSTFIELDS,json_encode($input,JSON_THROW_ON_ERROR));}
    $raw=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);
    return [$status,json_decode((string)$raw,true,32,JSON_THROW_ON_ERROR)];
}
function releaseCaptcha(string $scene):array {
    $id=bin2hex(random_bytes(24));(new RedisStore())->command('SET','jianyun:captcha:'.$id,json_encode(['hash'=>hash('sha256','ABCDE'),'scene'=>$scene,'ip'=>'127.0.0.1']),'EX','300');
    return ['challenge_id'=>$id,'answer'=>'ABCDE'];
}
if(($argv[1]??'')==='empty') {
    releaseCheck((int)$db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()===27,'empty installation creates 27 tables');
    releaseCheck((int)$db->query('SELECT COUNT(*) FROM migrations')->fetchColumn()===8,'all eight Laravel migrations recorded');
    releaseCheck((int)$db->query('SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE()')->fetchColumn()===5,'all five business guards installed');
    foreach(['admins','users','providers','products','invoices','instances','tasks','transactions'] as $table){releaseCheck((int)$db->query("SELECT COUNT(*) FROM $table")->fetchColumn()===0,'fresh '.$table.' has no M2 or demo data');}
    try{$db->exec('CREATE DATABASE jianyun_forbidden');throw new RuntimeException('Database isolation absent');}catch(PDOException){releaseCheck(true,'application DB account cannot create another database');}
} elseif(($argv[1]??'')==='web') {
    releaseCheck(config('app.debug')===false && config('app.key')!=='' && (int)config('database.redis.default.database')===1,'production configuration cached with dedicated test namespace');
    releaseCheck(releaseHttp('POST','/admin/auth/login',['username'=>'release-admin','password'=>getenv('RELEASE_ADMIN_PASSWORD')])[0]===422,'default login captcha remains enforced');
    $admin=releaseHttp('POST','/admin/auth/login',['username'=>'release-admin','password'=>getenv('RELEASE_ADMIN_PASSWORD'),'captcha_payload'=>releaseCaptcha('login')]);
    releaseCheck($admin[0]===200,'newly provisioned administrator logs in through HTTPS/FPM');$at=$admin[1]['data']['token'];
    releaseCheck(releaseHttp('GET','/admin/tasks',null,$at)[0]===200 && releaseHttp('GET','/admin/tasks')[0]===401,'nginx forwards bearer credentials and denies anonymous admin access');
    $customer=releaseHttp('POST','/auth/register',['email'=>'release-customer@example.test','password'=>'ReleaseCustomer123','captcha_payload'=>releaseCaptcha('register')]);
    releaseCheck($customer[0]===200,'fresh customer registration with isolated captcha succeeds');$ct=$customer[1]['data']['token'];
    releaseCheck(releaseHttp('GET','/admin/tasks',null,$ct)[0]===401 && releaseHttp('GET','/auth/me',null,$at)[0]===401,'client and administrator permissions remain separate');
    $order=['product_id'=>1,'billing_cycle'=>'monthly','hostname'=>'Release8','password'=>'ReleaseRoot123','checkout_key'=>'fresh-release-order'];
    $invoice=releaseHttp('POST','/orders/checkout',$order,$ct);
    releaseCheck($invoice[0]===200 && $invoice[1]['data']['total']==='0.01','fresh catalog creates the quoted unpaid invoice');$id=(int)$invoice[1]['data']['invoice_id'];
    releaseCheck(releaseHttp('POST','/orders/checkout',$order,$ct)[1]['data']['invoice_id']===$id,'checkout replay resumes original invoice');
    $pay=releaseHttp('POST','/invoices/'.$id.'/pay',['gateway'=>'epay'],$ct);
    releaseCheck($pay[0]===200 && isset($pay[1]['data']['checkout']['fields']),'payment checkout uses frozen original invoice');
    $fields=$pay[1]['data']['checkout']['fields'];
    $params=['pid'=>'release-merchant','out_trade_no'=>$fields['out_trade_no'],'trade_no'=>'release-trade-1','money'=>'0.01','trade_status'=>'TRADE_SUCCESS','sign_type'=>'MD5'];
    $sorted=$params;unset($sorted['sign_type']);ksort($sorted);$parts=[];foreach($sorted as $k=>$v){$parts[]=$k.'='.$v;}$params['sign']=md5(implode('&',$parts).getenv('RELEASE_PAYMENT_KEY'));
    for($n=0;$n<2;$n++){
        $c=curl_init(getenv('RELEASE_URL').'/api/v1/payments/epay/notify');curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($params),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CAINFO=>getenv('RELEASE_CERT')]);
        $raw=curl_exec($c);$code=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);releaseCheck($code===200 && $raw==='success','form callback acknowledged literally, attempt '.($n+1));
    }
    $c=curl_init(getenv('RELEASE_URL').'/api/v1/payments/epay/notify?'.http_build_query($params));curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CAINFO=>getenv('RELEASE_CERT')]);
    $raw=curl_exec($c);$code=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);releaseCheck($code===200 && $raw==='success','GET callback has the same acknowledgement and deduplication');
    foreach(['payment_records','tasks'] as $table){releaseCheck((int)$db->query("SELECT COUNT(*) FROM $table WHERE invoice_id=$id")->fetchColumn()===1,'duplicate callback creates one '.$table);}
    releaseCheck((int)$db->query('SELECT COUNT(*) FROM transactions')->fetchColumn()===0 && $db->query('SELECT balance FROM users LIMIT 1')->fetchColumn()==='0.00','direct external payment does not fabricate wallet ledger movements');
    releaseCheck($db->query('SELECT total_spent FROM users LIMIT 1')->fetchColumn()==='0.01','duplicate receipt counts customer spending once');
    releaseCheck($db->query("SELECT status FROM invoices WHERE id=$id")->fetchColumn()==='paid','callback settles original invoice');
    $settings=(new App\Services\NotificationSettingsService())->load();
    releaseCheck($settings!==null && $settings['config']['security']==='smtps' && $settings['due_reminders']===false,'encrypted SMTP configuration loaded; automatic reminders off');
    $bad=$settings;unset($bad['config']);$bad['config']=$settings['config'];$bad['config']['security']='test_plain';
    try{(new App\Services\NotificationSettingsService())->configure(1,$bad);throw new RuntimeException('Plain SMTP accepted');}catch(App\Core\ApiException){releaseCheck(true,'production rejects plaintext test SMTP');}
    releaseCheck(releaseHttp('POST','/auth/password/forgot',['email'=>'release-customer@example.test','captcha_payload'=>releaseCaptcha('password_reset')])[0]===200,'password reset queues local-only acceptance email');
} elseif(($argv[1]??'')==='delivered') {
    releaseCheck($db->query('SELECT status FROM instances WHERE id=1')->fetchColumn()==='active','non-root Worker delivers from original Outbox');
    releaseCheck($db->query('SELECT status FROM tasks WHERE id=1')->fetchColumn()==='success','delivery task reaches success');
    releaseCheck((int)$db->query('SELECT COUNT(*) FROM transactions')->fetchColumn()===0 && $db->query('SELECT total_spent FROM users LIMIT 1')->fetchColumn()==='0.01','delivery does not charge customer again');
    releaseCheck($db->query('SELECT status FROM notification_deliveries LIMIT 1')->fetchColumn()==='success','mail Worker sends password reset via verified TLS fixture');
    releaseCheck((int)$db->query("SELECT COUNT(*) FROM admin_audit_logs WHERE action='admin_provision'")->fetchColumn()===1,'administrator initialization remains audited exactly once');
} else { throw new RuntimeException('Unknown rehearsal phase'); }
echo "Deployment phase checks: $checks\n";
