<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
set_exception_handler(static function(Throwable $e):never { fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1); });
use App\Core\{Database,Security};
use App\Services\{NotificationService,NotificationSettingsService};
use App\Plugins\Notifications\{NotificationRegistry,NotificationMessage,NotificationResult};
if (config('app.env')!=='test'||config('database.connections.mysql.database')!=='jianyun_test') { throw new RuntimeException('Isolated database required'); }
$db=Database::getInstance();$checks=0;$service=new NotificationService();$settingsService=new NotificationSettingsService();
function mailCheck(bool $v,string $label):void { global $checks;if (!$v) { throw new RuntimeException('FAIL mail: '.$label); }$checks++;echo "PASS mail: $label\n"; }
function mailReject(callable $f,string $label):void { try { $f(); }catch(Throwable) { mailCheck(true,$label);return; }throw new RuntimeException('Expected rejection: '.$label); }
function mailState(string $path='/state'):array { return json_decode(file_get_contents(getenv('SMTP_TEST_CONTROL').$path),true,32,JSON_THROW_ON_ERROR); }
function delivery(int $id):array { $s=Database::getInstance()->prepare('SELECT * FROM notification_deliveries WHERE id=?');$s->execute([$id]);return $s->fetch(); }
function isolateDelivery(int $id):void { Database::getInstance()->prepare("UPDATE notification_deliveries SET status='failed',error_message='TEST_OTHER_EVENT' WHERE status='pending' AND id<>?")->execute([$id]); }
mailCheck($settingsService->load()===null && $service->dispatch()['enabled']===false,'unconfigured channel does not create or send messages');
$driver=NotificationRegistry::installed()->get('mail');
mailCheck($driver->getName()==='SMTP 邮件','mail plugin is loaded through the physical manifest registry');
mailReject(fn()=>(new NotificationRegistry(['mail'=>fn()=>new stdClass()]))->get('mail'),'registry rejects invalid notification contract');
mailReject(fn()=>NotificationRegistry::installed()->get('telegram'),'uninstalled notification channel fails closed');
$db->prepare("INSERT INTO admins(username,email,password,role) VALUES('notify-root','notify-admin@example.test',?,'super_admin')")->execute([Security::hashPassword('NotifyTestRoot12')]);$admin=(int)$db->lastInsertId();
$db->prepare("INSERT INTO admins(username,email,password,role) VALUES('notify-support','notify-support@example.test',?,'support')")->execute([Security::hashPassword('NotifySupport12')]);$support=(int)$db->lastInsertId();
$db->prepare("INSERT INTO users(email,password) VALUES('notify-customer@example.test',?)")->execute([Security::hashPassword('NotifyUserTest12')]);$user=(int)$db->lastInsertId();
$db->prepare("INSERT INTO users(email,password) VALUES('notify-other@example.test',?)")->execute([Security::hashPassword('NotifyOtherTest12')]);$other=(int)$db->lastInsertId();
$db->exec("INSERT INTO providers(name,driver) VALUES('通知隔离上游','manual')");$provider=(int)$db->lastInsertId();
$db->prepare("INSERT INTO products(provider_id,name,markup_type) VALUES(?,'通知隔离商品','none')")->execute([$provider]);
$product=['id'=>(int)$db->lastInsertId(),'provider_id'=>$provider];
$db->prepare("INSERT INTO instances(user_id,product_id,provider_id,remote_instance_id,name,status,recurring_price,due_date) VALUES(?,?,?,'notification-fixture','通知测试','active','16.00',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 2 DAY))")->execute([$user,$product['id'],$product['provider_id']]);$instance=(int)$db->lastInsertId();
$db->prepare("INSERT INTO tasks(instance_id,idempotency_key,action,status,execution_token,last_error) VALUES(?,'notification-fixture','power','failed',1,'MANUAL_AUDIT_REQUIRED:TEST')")->execute([$instance]);$task=(int)$db->lastInsertId();
$config=['enabled'=>true,'task_alerts'=>true,'due_reminders'=>true,'site_url'=>'https://shop.example.test','admin_ids'=>[$admin],
    'config'=>['host'=>'127.0.0.1','port'=>(int)getenv('SMTP_TEST_PORT'),'security'=>'test_plain','username'=>'','password'=>'TEST_PRIVATE_SMTP_PASSWORD','from'=>'shop@example.test','from_name'=>'樱花主机 · 隔离验收']];
mailReject(fn()=>$settingsService->configure($support,$config),'support cannot configure mail');
$bad=$config;$bad['site_url']='https://shop.example.test/?token=secret';mailReject(fn()=>$settingsService->configure($admin,$bad),'site links cannot embed query credentials');
$bad=$config;$bad['config']['unexpected']='secret';mailReject(fn()=>$settingsService->configure($admin,$bad),'private configuration rejects unrecognized fields');
$settingsService->configure($admin,$config);$settings=$settingsService->load();
$row=$db->query("SELECT * FROM system_settings WHERE `key`='notify.mail'")->fetch();
mailCheck(!str_contains(json_encode($row),'TEST_PRIVATE_SMTP_PASSWORD') && Security::decrypt($row['encrypted_credentials'])!==null,'SMTP configuration encrypted at rest');
mailCheck(!str_contains($db->query("SELECT context FROM admin_audit_logs WHERE action='notification_configure' ORDER BY id DESC LIMIT 1")->fetchColumn(),'TEST_PRIVATE_SMTP_PASSWORD'),'configuration audit excludes secrets');
$before=[];foreach (['users','instances','invoices','transactions','payment_records','tasks'] as $table) { $before[$table]=$db->query("SELECT * FROM $table ORDER BY id")->fetchAll(); }
$service->generate();$n=(int)$db->query("SELECT COUNT(*) FROM notification_deliveries")->fetchColumn();$service->generate();
mailCheck((int)$db->query("SELECT COUNT(*) FROM notification_deliveries")->fetchColumn()===$n,'repeat source sweep preserves one fingerprint/channel placeholder');
$find=function(string $kind)use($db,$instance):int { $s=$db->prepare("SELECT id FROM notification_deliveries WHERE JSON_UNQUOTE(JSON_EXTRACT(context,'$.kind'))=? AND CAST(JSON_UNQUOTE(JSON_EXTRACT(context,'$.instance_id')) AS UNSIGNED)=? ORDER BY id DESC LIMIT 1");$s->execute([$kind,$instance]);return (int)$s->fetchColumn(); };
$taskDelivery=$find('task_manual');$dueDelivery=$find('due_reminder');
mailCheck(delivery($taskDelivery)['user_id']===null && (int)delivery($taskDelivery)['admin_id']===$admin,'task alert has administrator recipient, never a customer user ID');
mailCheck(delivery($dueDelivery)['admin_id']===null && (int)delivery($dueDelivery)['user_id']===$user,'reminder has customer recipient');
mailState('/reset/normal');
foreach ([$taskDelivery,$dueDelivery] as $id) {
    $claim=$service->claim($id,$settings);mailCheck($claim!==null,'eligible notification can be claimed once: '.$id);
    mailCheck($service->claim($id,$settings)===null,'claimed notification cannot be claimed again: '.$id);
    $result=$driver->send($claim['target'],$claim['message'],$settings['config']);mailCheck($result->status==='accepted','real SMTP fixture accepts UTF-8 notification: '.$id);
    mailCheck($service->finish($id,$result) && delivery($id)['status']==='success','accepted SMTP confirmation persists: '.$id);
}
$state=mailState();mailCheck($state['data_attempts']===2,'one DATA submission per claimed business event');
$text='';foreach ($state['messages'] as $m) { $text.=$m['body']; }
// Decode MIME text via the known UTF-8 quoted-printable body, not raw HTML or upstream responses.
$decoded=quoted_printable_decode($text);
mailCheck(str_contains($decoded,'/admin/tasks/'.$task) && str_contains($decoded,'/instances/'.$instance),'mail links use site task and instance pages');
mailCheck(!str_contains($text,'TEST_PRIVATE_SMTP_PASSWORD') && !str_contains($text,'host_token'),'message does not expose SMTP credentials or upstream payload');
foreach ($before as $table=>$rows) { mailCheck($db->query("SELECT * FROM $table ORDER BY id")->fetchAll()===$rows,'notifications do not modify '.$table); }
// New cycle for concurrency acceptance; old placeholders are retained.
$db->prepare('UPDATE instances SET due_date=DATE_ADD(due_date,INTERVAL 1 HOUR) WHERE id=?')->execute([$instance]);$service->generate();$concurrent=$find('due_reminder');isolateDelivery($concurrent);mailState('/reset/normal');
$jobs=[];
for ($j=0;$j<4;$j++) { $pipes=[];$p=proc_open([PHP_BINARY,__DIR__.'/notification_process.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$jobs[]=[$p,$pipes]; }
foreach ($jobs as [$p,$pipes]) { $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);foreach ($pipes as $pipe) { fclose($pipe); }mailCheck(proc_close($p)===0 && str_contains($out,'processed'),'concurrent dispatcher exits cleanly'); }
mailCheck(mailState()['data_attempts']===1 && delivery($concurrent)['status']==='success','four dispatchers produce exactly one SMTP DATA for the pending event');
$service->dispatch();mailCheck(mailState()['data_attempts']===1,'success and failed placeholders are not replayed');
// Enqueue against the old due date, then renew before sending.
$db->prepare('UPDATE instances SET due_date=DATE_ADD(due_date,INTERVAL 1 HOUR) WHERE id=?')->execute([$instance]);$service->generate();$stale=$find('due_reminder');
$db->prepare('UPDATE instances SET due_date=DATE_ADD(due_date,INTERVAL 1 MONTH) WHERE id=?')->execute([$instance]);
mailCheck($service->claim($stale,$settings)===null && delivery($stale)['error_message']==='STALE_BUSINESS_CONTEXT','renewed instance suppresses the queued old-period reminder');
mailCheck(mailState()['data_attempts']===1,'stale reminder never reaches SMTP');
// Remaining source gates are checked after enqueue, not merely at generation time.
foreach (['owner','email','suspended','expired','day','cycle'] as $gate) {
    $db->prepare("UPDATE users SET email='notify-customer@example.test' WHERE id=?")->execute([$user]);
    $db->prepare("UPDATE instances SET user_id=?,status='active',billing_cycle='monthly',due_date=DATE_ADD(DATE_ADD(UTC_TIMESTAMP(),INTERVAL 2 DAY),INTERVAL ? SECOND) WHERE id=?")->execute([$user,100+$checks,$instance]);
    $service->generate();$id=$find('due_reminder');
    if ($gate==='owner') { $db->prepare('UPDATE instances SET user_id=? WHERE id=?')->execute([$other,$instance]); }
    elseif ($gate==='email') { $db->prepare("UPDATE users SET email='changed-notify@example.test' WHERE id=?")->execute([$user]); }
    elseif ($gate==='suspended') { $db->prepare("UPDATE instances SET status='suspended' WHERE id=?")->execute([$instance]); }
    elseif ($gate==='expired') { $db->prepare('UPDATE instances SET due_date=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id=?')->execute([$instance]); }
    elseif ($gate==='day') { $db->prepare("UPDATE notification_deliveries SET context=JSON_SET(context,'$.reminder_day','2000-01-01') WHERE id=?")->execute([$id]); }
    else { $db->prepare("UPDATE instances SET billing_cycle='onetime' WHERE id=?")->execute([$instance]); }
    mailCheck($service->claim($id,$settings)===null && delivery($id)['status']==='failed','send-time reminder gate: '.$gate);
}
$db->prepare("UPDATE instances SET user_id=?,status='active',billing_cycle='monthly',due_date=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 MONTH) WHERE id=?")->execute([$user,$instance]);
$db->prepare("UPDATE users SET email='notify-customer@example.test' WHERE id=?")->execute([$user]);
// Task was resolved, recipient disabled/retargeted, or original task epoch changed.
foreach (['resolved','epoch','disabled','email','removed'] as $gate) {
    $db->prepare("UPDATE tasks SET status='failed',last_error='MANUAL_AUDIT_REQUIRED:TEST',execution_token=execution_token+1 WHERE id=?")->execute([$task]);
    $db->prepare("UPDATE admins SET email='notify-admin@example.test',status='active' WHERE id=?")->execute([$admin]);
    $service->generate();$id=$find('task_manual');$local=$settings;
    if ($gate==='resolved') { $db->prepare("UPDATE tasks SET status='success',last_error=NULL WHERE id=?")->execute([$task]); }
    elseif ($gate==='epoch') { $db->prepare('UPDATE tasks SET execution_token=execution_token+1 WHERE id=?')->execute([$task]); }
    elseif ($gate==='disabled') { $db->prepare("UPDATE admins SET status='disabled' WHERE id=?")->execute([$admin]); }
    elseif ($gate==='email') { $db->prepare("UPDATE admins SET email='retargeted-admin@example.test' WHERE id=?")->execute([$admin]); }
    else { $local['admin_ids']=[]; }
    mailCheck($service->claim($id,$local)===null && delivery($id)['status']==='failed','send-time task gate: '.$gate);
}
$db->prepare("UPDATE admins SET email='notify-admin@example.test',status='active' WHERE id=?")->execute([$admin]);
// Each new task token is a real new manual-review incident; no token is changed by the sender itself.
$newTask=function()use($db,$task,$service,$find):int { $db->prepare("UPDATE tasks SET status='failed',last_error='MANUAL_AUDIT_REQUIRED:TEST',execution_token=execution_token+1 WHERE id=?")->execute([$task]);$service->generate();$id=$find('task_manual');isolateDelivery($id);return $id; };
mailState('/reset/lost_response');$unknown=$newTask();$service->dispatch();
mailCheck(delivery($unknown)['status']==='pending_manual_check' && mailState()['data_attempts']===1,'accepted DATA with lost response becomes persistent manual review');
$service->dispatch();mailCheck(mailState()['data_attempts']===1,'unknown SMTP result never resends');
mailReject(fn()=>$service->resolve($support,$unknown,['status'=>'success','expected_status'=>'pending_manual_check','evidence'=>'SMTP server queue accepted this message']),'support cannot close unknown mail outcome');
mailReject(fn()=>$service->resolve($admin,$unknown,['status'=>'success','expected_status'=>'pending_manual_check','evidence'=>'short']),'manual resolution requires evidence');
$service->resolve($admin,$unknown,['status'=>'success','expected_status'=>'pending_manual_check','evidence'=>'SMTP server queue accepted this message']);
mailCheck(delivery($unknown)['status']==='success','evidence-based manual acceptance closes unknown delivery');
mailCheck(!$service->finish($unknown,new NotificationResult('rejected','LATE_WORKER')),'late worker cannot overwrite manual final outcome');
mailReject(fn()=>$service->resolve($admin,$unknown,['status'=>'failed','expected_status'=>'pending_manual_check','evidence'=>'A stale operator must never overwrite the final state']),'duplicate and stale manual action rejected');
$service->dispatch();mailCheck(mailState()['data_attempts']===1,'manual resolution never triggers a second external send');
mailState('/reset/normal');$crash=$newTask();$claim=$service->claim($crash,$settings);
$db->prepare('UPDATE notification_deliveries SET updated_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 16 MINUTE) WHERE id=?')->execute([$crash]);
mailCheck($service->recover()===1 && delivery($crash)['status']==='pending_manual_check','crash after claim but before send is quarantined conservatively');
mailCheck(!$service->finish($crash,new NotificationResult('accepted','SLOW_WORKER')),'recovery fences a slow sender from overwriting review state');
$service->dispatch();mailCheck(mailState()['data_attempts']===0,'expired sending claim is never reclaimed or resent');
$db->exec("CREATE TRIGGER test_mail_audit_failure BEFORE INSERT ON admin_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test audit failure'");
try { mailReject(fn()=>$service->resolve($admin,$crash,['status'=>'failed','expected_status'=>'pending_manual_check','evidence'=>'Confirmed no SMTP acceptance in the server log']),'audit failure rolls back manual resolution'); }
finally { $db->exec('DROP TRIGGER test_mail_audit_failure'); }
mailCheck(delivery($crash)['status']==='pending_manual_check','failed audit preserves manual-review state');
$service->resolve($admin,$crash,['status'=>'failed','expected_status'=>'pending_manual_check','evidence'=>'Confirmed no SMTP acceptance in the server log']);
mailState('/reset/reject');$rejected=$newTask();$service->dispatch();mailCheck(delivery($rejected)['status']==='failed','authoritative SMTP rejection is terminal failed');
$service->dispatch();mailCheck(mailState()['data_attempts']===0,'rejected message is not automatically retried');
$r=$driver->send('notify-admin@example.test',new NotificationMessage('bad-config','test','test'),array_replace($config['config'],['security'=>'test_plain','host'=>'remote.example.test']));
mailCheck($r->status==='rejected','plaintext SMTP test exception is limited to test loopback');
$config['enabled']=false;$settingsService->configure($admin,$config);$disabled=$newTask();
mailCheck($service->dispatch()['enabled']===false && mailState()['data_attempts']===0,'disabled channel does not send backlog');
mailCheck(!$service->runOnce(),'independent sender honors disabled configuration');
mailCheck(!str_contains(json_encode($service->inspect()),'TEST_PRIVATE_SMTP_PASSWORD'),'operational inspection excludes SMTP credentials');
mailCheck((int)$db->query("SELECT COUNT(*) FROM admin_audit_logs WHERE action='notification_resolve'")->fetchColumn()===2,'each successful resolution has exactly one immutable audit record');
$config['enabled']=true;$settingsService->configure($admin,$config);$separate=$newTask();mailState('/reset/normal');
$service->sweep();mailCheck(delivery($separate)['status']==='pending' && mailState()['data_attempts']===0,'minute sweep enqueues only, with no SMTP network call');
mailCheck($service->runOnce() && delivery($separate)['status']==='success' && mailState()['data_attempts']===1,'independent sender consumes a persistent pending mail');
$throwing=new class implements \App\Plugins\Notifications\NotificationChannelInterface {
    public function getChannelId():string { return 'mail'; }
    public function getName():string { return 'Throwing fixture'; }
    public function getConfigSchema():array { return []; }
    public function send(string $target,NotificationMessage $message,array $config):NotificationResult { throw new RuntimeException('PRIVATE raw SMTP transcript'); }
};
$throwId=$newTask();(new NotificationService(new NotificationRegistry(['mail'=>fn()=>$throwing])))->runOnce();
mailCheck(delivery($throwId)['status']==='pending_manual_check' && delivery($throwId)['error_message']==='CHANNEL_OUTCOME_UNKNOWN','unexpected driver failure is quarantined with a safe code');
$config['enabled']=false;$settingsService->configure($admin,$config);
echo "Notification checks: $checks\n";
