<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use App\Core\{Database,Security};
use App\Services\{AdminAuthService,AuthService,NotificationSettingsService};
if(config('app.env')!=='test'||config('database.connections.mysql.database')!=='jianyun_test'){throw new RuntimeException('Isolated database required');}
$db=Database::getInstance();$checks=0;$root='/admin/notification-settings';
function nsCheck(bool $ok,string $label):void {global $checks;if(!$ok){throw new RuntimeException('FAIL: '.$label);}$checks++;echo 'PASS: '.$label.PHP_EOL;}
function nsHttp(string $path,?string $token=null,string $method='GET',?array $body=null):array {
 $h=curl_init(getenv('TEST_BASE_URL').'/api/v1'.$path);$headers=['Accept: application/json','Content-Type: application/json'];if($token){$headers[]='Authorization: Bearer '.$token;}$cache='';
 curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>40,CURLOPT_HEADERFUNCTION=>static function($h,$line)use(&$cache){if(str_starts_with(strtolower($line),'cache-control:')){$cache=trim(substr($line,14));}return strlen($line);}]);
 if($body!==null){curl_setopt($h,CURLOPT_POSTFIELDS,json_encode($body,JSON_THROW_ON_ERROR));}$raw=curl_exec($h);$code=curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);return [$code,json_decode($raw,true,32,JSON_THROW_ON_ERROR),$cache];
}
function nsRow(string $key): ?array {global $db;$s=$db->prepare('SELECT * FROM system_settings WHERE `key`=?');$s->execute([$key]);return $s->fetch()?:null;}
function nsGet():array {global $root,$token;return nsHttp($root,$token)[1]['data'];}
function nsBody(array $m):array {unset($m['version'],$m['password_configured']);return $m;}
function nsSnapshot():string {global $db;$rows=[];foreach(['users','products','providers','instances','invoices','payment_records','transactions','tasks','tickets','ticket_replies','notification_deliveries'] as $table){$rows[$table]=$db->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll();}return hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));}
$tokens=[];$ids=[];foreach(['super_admin','finance','support','operator'] as $role){$ids[$role]=(new AdminAuthService())->create(['username'=>'notify-settings-'.$role,'email'=>'notify-settings-'.$role.'@example.test','password'=>'NotifySettings!2026','role'=>$role]);$tokens[$role]=Security::generateToken(['uid'=>$ids[$role],'sub_type'=>'admin']);}
$token=$tokens['super_admin'];$actor=$ids['super_admin'];$customer=(new AuthService())->register(['email'=>'notify-settings-customer@example.test','password'=>'NotifySettings!2026']);
foreach([null,$customer['token']] as $t){foreach([[$root,'GET',null],[$root.'/mail','PATCH',[]],[$root.'/tickets','PATCH',[]]] as [$path,$method,$body]){nsCheck(nsHttp($path,$t,$method,$body)[0]===401,'anonymous/customer cannot access settings');}}
foreach(['finance','support','operator'] as $role){nsCheck(nsHttp($root,$tokens[$role])[0]===403,'SMTP metadata and recipients denied '.$role);}
// Test both absent and disabled configurations, never touch a business database.
$db->exec("DELETE FROM system_settings WHERE `key` IN ('notify.mail','notify.ticket_mail')");
$state=nsGet();nsCheck(!$state['mail']['enabled']&&!$state['tickets']['enabled']&&!$state['mail']['password_configured']&&$state['mail']['config']['port']===465,'empty configuration is safely editable and disabled');
$body=['enabled'=>false,'task_alerts'=>true,'due_reminders'=>false,'site_url'=>'https://notifications.example.test','subject_prefix'=>'[隔离]','admin_ids'=>[$actor],'config'=>['host'=>'127.0.0.1','port'=>(int)getenv('SMTP_TEST_PORT'),'security'=>'test_plain','username'=>'fixture','from'=>'fixture@example.test','from_name'=>'隔离测试'],'password_action'=>'replace','password'=>'NS_PRIVATE_PASSWORD_123','expected_version'=>$state['mail']['version']];
$baseline=nsSnapshot();$r=nsHttp($root.'/mail',$token,'PATCH',$body);nsCheck($r[0]===200&&str_contains($r[2],'no-store'),'create disabled SMTP configuration with no-store');
$raw=nsRow('notify.mail');nsCheck(!str_contains($raw['encrypted_credentials'],'NS_PRIVATE')&&json_decode(Security::decrypt($raw['encrypted_credentials']),true)['password']===$body['password'],'password encrypted in original settings row');
nsCheck(nsHttp($root.'/mail',$token,'PATCH',$body)[0]===409,'lost-response replay cannot overwrite a newer configuration');
$state=nsGet();$encoded=json_encode(nsHttp($root,$token));nsCheck($state['mail']['password_configured']&&$state['mail']['config']['host']==='127.0.0.1'&&!str_contains($encoded,'NS_PRIVATE')&&!str_contains($encoded,$raw['encrypted_credentials'])&&!isset($state['mail']['config']['password']),'disabled SMTP public fields readable; secret and ciphertext withheld');
foreach(['finance','support','operator'] as $role){nsCheck(nsHttp($root.'/mail',$tokens[$role],'PATCH',$body)[0]===403&&nsHttp($root.'/tickets',$tokens[$role],'PATCH',['enabled'=>false,'admin_ids'=>[],'expected_version'=>$state['tickets']['version'],'confirm_new_period'=>true])[0]===403,'writes recheck current super_admin '.$role);}
$body=nsBody($state['mail'])+['expected_version'=>$state['mail']['version'],'password_action'=>'keep'];$body['enabled']=true;
nsCheck(nsHttp($root.'/mail',$token,'PATCH',$body)[0]===200,'keep retains original password while enabling mail');
nsCheck(json_decode(Security::decrypt(nsRow('notify.mail')['encrypted_credentials']),true)['password']==='NS_PRIVATE_PASSWORD_123','retained secret not a UI placeholder');
$state=nsGet();$ticket=['expected_version'=>$state['tickets']['version'],'enabled'=>true,'admin_ids'=>[$actor],'confirm_new_period'=>true];
nsCheck(nsHttp($root.'/tickets',$token,'PATCH',$ticket)[0]===200,'explicit subscription can be enabled independently');
$subscription=nsRow('notify.ticket_mail');$mail=nsRow('notify.mail');
nsCheck(nsHttp($root.'/tickets',$token,'PATCH',$ticket)[0]===409&&nsRow('notify.ticket_mail')===$subscription,'duplicate/stale subscription save cannot switch period again');
$state=nsGet();$body=nsBody($state['mail'])+['expected_version'=>$state['mail']['version'],'password_action'=>'keep'];$body['subject_prefix']='new-prefix';
nsCheck(nsHttp($root.'/mail',$token,'PATCH',$body)[0]===200&&nsRow('notify.ticket_mail')===$subscription,'normal save leaves complete ticket subscription row untouched');
$state=nsGet();$mailBeforeTicket=nsRow('notify.mail');$ticket['expected_version']=$state['tickets']['version'];$ticket['admin_ids']=[$ids['support']];
nsCheck(nsHttp($root.'/tickets',$token,'PATCH',$ticket)[0]===200&&nsRow('notify.mail')===$mailBeforeTicket,'recipient replacement commits a distinct period');
$new=json_decode(nsRow('notify.ticket_mail')['value'],true);$old=json_decode($subscription['value'],true);nsCheck($new['activation']!==$old['activation']&&$new['admin_ids']===[$ids['support']],'recipient change advances server-generated identity');
$state=nsGet();$body=nsBody($state['mail'])+['expected_version'=>$state['mail']['version'],'password_action'=>'keep'];
foreach([['password'=>'placeholder'],['password_action'=>'replace','password'=>''],['config'=>$body['config']+['password'=>'hidden-bypass']],['activation'=>'forge'],['enabled'=>1],['site_url'=>'https://example.test/path'],['admin_ids'=>[$customer['user']['id']+999999]],['expected_version'=>str_repeat('0',64)]] as $change){$code=nsHttp($root.'/mail',$token,'PATCH',array_replace($body,$change))[0];nsCheck(in_array($code,[400,409],true),'reject unsafe settings/recipient/version input');}
$ticket=['expected_version'=>$state['tickets']['version'],'enabled'=>true,'admin_ids'=>[$actor],'confirm_new_period'=>true];
foreach([['confirm_new_period'=>false],['activation'=>'forge'],['admin_ids'=>[]],['enabled'=>'true']] as $change){nsCheck(nsHttp($root.'/tickets',$token,'PATCH',array_replace($ticket,$change))[0]===400,'subscription requires explicit valid settings and confirmation');}
$body['password_action']='replace';$body['password']='NS_REPLACED_PRIVATE_PASSWORD';nsCheck(nsHttp($root.'/mail',$token,'PATCH',$body)[0]===200,'explicit password replacement accepted');
nsCheck(json_decode(Security::decrypt(nsRow('notify.mail')['encrypted_credentials']),true)['password']==='NS_REPLACED_PRIVATE_PASSWORD','new password persisted exactly once');
$audit=$db->query("SELECT context FROM admin_audit_logs WHERE action IN ('notification_configure','ticket_notification_configure') ORDER BY id DESC LIMIT 10")->fetchAll();nsCheck(!str_contains(json_encode($audit),'NS_PRIVATE')&&!str_contains(json_encode($audit),'NS_REPLACED_PRIVATE'),'audits contain no password');
$last=json_decode($audit[0]['context'],true);nsCheck($last['source']==='admin_web'&&$last['password_action']==='replace','audit records secret replacement intent without secret');
// CLI changes participate in the same revision; a stale browser must not win.
$state=nsGet();$cli=nsBody($state['mail']);$cli['config']['password']='CLI_PRIVATE';(new NotificationSettingsService())->configure($actor,$cli);
$body=nsBody($state['mail'])+['expected_version'=>$state['mail']['version'],'password_action'=>'keep'];nsCheck(nsHttp($root.'/mail',$token,'PATCH',$body)[0]===409,'CLI change invalidates browser version');
$state=nsGet();$body=nsBody($state['mail'])+['expected_version'=>$state['mail']['version'],'password_action'=>'keep'];$ticket=['expected_version'=>$state['tickets']['version'],'enabled'=>false,'admin_ids'=>[],'confirm_new_period'=>true];
$before=[nsRow('notify.mail'),nsRow('notify.ticket_mail')];$db->exec("CREATE TRIGGER notify_settings_audit_fault BEFORE INSERT ON admin_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='ISOLATED AUDIT FAULT'");
try{nsCheck(nsHttp($root.'/mail',$token,'PATCH',$body)[0]===500&&nsHttp($root.'/tickets',$token,'PATCH',$ticket)[0]===500,'audit failure rejects both config writers');}finally{$db->exec('DROP TRIGGER notify_settings_audit_fault');}
nsCheck([nsRow('notify.mail'),nsRow('notify.ticket_mail')]===$before,'audit failure rolls back ciphertext, switches and subscription identity');
// Competing writers use the same original revision. Exactly one can commit.
$multi=curl_multi_init();$handles=[];for($i=0;$i<4;$i++){$h=curl_init(getenv('TEST_BASE_URL').'/api/v1'.$root.'/mail');$input=$body;$input['subject_prefix']='concurrent-'.$i;curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>'PATCH',CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$token],CURLOPT_POSTFIELDS=>json_encode($input),CURLOPT_TIMEOUT=>40]);curl_multi_add_handle($multi,$h);$handles[]=$h;}
do{curl_multi_exec($multi,$running);if($running){curl_multi_select($multi,.2);}}while($running);$codes=[];foreach($handles as $h){$codes[]=curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_multi_remove_handle($multi,$h);curl_close($h);}curl_multi_close($multi);sort($codes);nsCheck($codes===[200,409,409,409],'four competing config saves commit once');
$state=nsGet();$body=nsBody($state['mail'])+['expected_version'=>$state['mail']['version'],'password_action'=>'keep'];$db->prepare("UPDATE admins SET role='support' WHERE id=?")->execute([$actor]);
nsCheck(nsHttp($root,$token)[0]===403&&nsHttp($root.'/mail',$token,'PATCH',$body)[0]===403,'existing token loses access immediately after demotion');$db->prepare("UPDATE admins SET role='super_admin' WHERE id=?")->execute([$actor]);
nsCheck(nsSnapshot()===$baseline,'settings never send mail or mutate tickets, funds, tasks and resources');
// The sender must reload after a configuration save, without losing the original placeholder.
$tickets=new App\Services\TicketService();$sender=new App\Services\NotificationService();$settings=new NotificationSettingsService();
$made=$tickets->create((int)$customer['user']['id'],['request_key'=>(string)\Illuminate\Support\Str::uuid(),'title'=>'settings claim fixture','content'=>'plain fixture message']);
$s=$db->prepare("SELECT id FROM notification_deliveries WHERE JSON_UNQUOTE(JSON_EXTRACT(context,'$.reply_id'))=? ORDER BY id DESC LIMIT 1");$s->execute([$made['reply_id']]);$delivery=(int)$s->fetchColumn();
$oldSettings=$settings->load();$state=nsGet();$body=nsBody($state['mail'])+['expected_version'=>$state['mail']['version'],'password_action'=>'keep'];$body['site_url']='https://changed.example.test';
nsCheck(nsHttp($root.'/mail',$token,'PATCH',$body)[0]===200,'config can change between load and sender claim');
nsCheck($sender->claim($delivery,$oldSettings)===null,'superseded SMTP snapshot cannot claim an eligible message');
$s=$db->prepare('SELECT status FROM notification_deliveries WHERE id=?');$s->execute([$delivery]);nsCheck($s->fetchColumn()==='pending','superseded settings leave original event pending for a fresh read');
$claim=$sender->claim($delivery,$settings->load());nsCheck($claim!==null&&str_contains($claim['message']->text,'https://changed.example.test/'),'normal save preserves queued subscription and uses the current origin');
$made=$tickets->create((int)$customer['user']['id'],['request_key'=>(string)\Illuminate\Support\Str::uuid(),'title'=>'subscription claim fixture','content'=>'plain fixture message']);
$s=$db->prepare("SELECT id FROM notification_deliveries WHERE JSON_UNQUOTE(JSON_EXTRACT(context,'$.reply_id'))=? ORDER BY id DESC LIMIT 1");$s->execute([$made['reply_id']]);$delivery=(int)$s->fetchColumn();
$state=nsGet();$ticket=['expected_version'=>$state['tickets']['version'],'enabled'=>true,'admin_ids'=>[$actor],'confirm_new_period'=>true];
nsCheck(nsHttp($root.'/tickets',$token,'PATCH',$ticket)[0]===200&&$sender->claim($delivery,$settings->load())===null,'explicit subscription change invalidates the old queued message without sending');
echo 'Admin notification settings checks: '.$checks.PHP_EOL;
