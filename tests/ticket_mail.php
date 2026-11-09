<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use App\Core\{ApiException,Database,Security};
use App\Services\{AdminAuthService,AuthService,NotificationService,NotificationSettingsService,TicketService};
use App\Plugins\Notifications\NotificationResult;
set_exception_handler(static function(Throwable $e):never{fwrite(STDERR,$e::class.': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
if(config('app.env')!=='test'||config('database.connections.mysql.database')!=='jianyun_test'){throw new RuntimeException('Isolated database required');}
$db=Database::getInstance();$tickets=new TicketService();$settings=new NotificationSettingsService();$notifications=new NotificationService();$checks=0;
function tmOp(array $v):array{global $tickets,$settings;return match($v['op']){'create'=>$tickets->create($v['actor'],$v['body']),'reply'=>$tickets->write($v['actor'],$v['admin']??false,$v['id'],$v['body']),'configure'=>$settings->configureTickets($v['actor'],$v['body'])};}
if(($argv[1]??'')==='--child'){$v=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);echo 'READY:'.$db->query('SELECT CONNECTION_ID()')->fetchColumn()."\n";fflush(STDOUT);echo json_encode(tmOp($v),JSON_THROW_ON_ERROR);exit;}
function tmCheck(bool $v,string $label):void{global $checks;if(!$v){throw new RuntimeException('FAIL ticket mail: '.$label);}$checks++;echo 'PASS ticket mail: '.$label."\n";}
function tmReject(callable $f,int $status,string $label):void{try{$f();}catch(ApiException $e){tmCheck($e->httpStatus===$status,$label);return;}throw new RuntimeException('Rejection missing: '.$label);}
function tmKey():string{$b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);$h=bin2hex($b);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);}
function tmBody(string $content='PRIVATE_MESSAGE_NOT_IN_EMAIL'):array{return ['content'=>$content,'request_key'=>tmKey()];}
function tmCreate():array{return ['title'=>'PRIVATE_TITLE_NOT_IN_EMAIL']+tmBody();}
function tmValue(string $sql,array $args=[]):mixed{global $db;$s=$db->prepare($sql);$s->execute($args);return $s->fetchColumn();}
function tmDeliveries(int $reply):array{global $db;$s=$db->prepare("SELECT * FROM notification_deliveries WHERE JSON_UNQUOTE(JSON_EXTRACT(context,'$.kind'))='ticket_update' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(context,'$.reply_id')) AS UNSIGNED)=? ORDER BY id");$s->execute([$reply]);return $s->fetchAll();}
function tmDelivery(int $id):array{global $db;$s=$db->prepare('SELECT * FROM notification_deliveries WHERE id=?');$s->execute([$id]);return $s->fetch();}
function tmCount():int{return (int)tmValue('SELECT COUNT(*) FROM notification_deliveries');}
function tmState(string $mode=''):array{return json_decode(file_get_contents(getenv('SMTP_TEST_CONTROL').($mode?'/reset/'.$mode:'/state')),true,32,JSON_THROW_ON_ERROR);}
function tmOnly(int $id):void{global $db;$db->prepare("UPDATE notification_deliveries SET status='failed',error_message='ISOLATED_OTHER_MESSAGE' WHERE status='pending' AND id<>?")->execute([$id]);}
function tmSpawn(array $v):array{$p=proc_open([PHP_BINARY,__FILE__,'--child'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,dirname(__DIR__));fwrite($pipes[0],json_encode($v,JSON_THROW_ON_ERROR));fclose($pipes[0]);stream_set_timeout($pipes[1],10);$line=trim(fgets($pipes[1])?:'');if(!preg_match('/^READY:([0-9]+)$/D',$line,$m)){throw new RuntimeException('Child not ready');}return [$p,$pipes,(int)$m[1]];}
function tmWait(array $c):void{$end=microtime(true)+8;do{if(tmValue('SELECT trx_state FROM information_schema.innodb_trx WHERE trx_mysql_thread_id=?',[$c[2]])==='LOCK WAIT'){return;}usleep(100000);}while(microtime(true)<$end);throw new RuntimeException('Expected configuration/source lock contention');}
function tmDone(array $c):array{[$p,$pipes]=$c;$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0){throw new RuntimeException('Child failed: '.$err.$out);}return json_decode($out,true,64,JSON_THROW_ON_ERROR);}
function tmFacts():array{global $db;$out=[];foreach(['users','instances','invoices','transactions','payment_records','tasks','products','providers','commissions'] as $t){$out[$t]=$db->query('SELECT * FROM '.$t.' ORDER BY id')->fetchAll();}return $out;}
$user=(new AuthService())->register(['email'=>'ticket-mail-client@example.test','password'=>'TicketMailClient!2026'])['user']['id'];
$other=(new AuthService())->register(['email'=>'ticket-mail-other@example.test','password'=>'TicketMailOther!2026'])['user']['id'];
$admin=(new AdminAuthService())->create(['username'=>'ticket-mail-root','email'=>'ticket-mail-root@example.test','password'=>'TicketMailRoot!2026','role'=>'super_admin']);
$support=(new AdminAuthService())->create(['username'=>'ticket-mail-support','email'=>'ticket-mail-support@example.test','password'=>'TicketMailSupport!2026','role'=>'support']);
$before=tmFacts();$historical=$tickets->create($user,tmCreate());$count=tmCount();
tmCheck(tmDeliveries($historical['reply_id'])===[],'default subscription disabled, existing message has no mail');
tmReject(fn()=>$settings->configureTickets($support,['enabled'=>true,'admin_ids'=>[$admin]]),403,'support cannot configure ticket mail');
tmReject(fn()=>$settings->configureTickets($admin,['enabled'=>true,'admin_ids'=>[$admin]]),400,'ticket activation requires an enabled mail channel');
$config=['enabled'=>true,'task_alerts'=>false,'due_reminders'=>false,'site_url'=>'https://shop.example.test','subject_prefix'=>'[隔离验收] ', 'admin_ids'=>[$admin],
 'config'=>['host'=>'127.0.0.1','port'=>(int)getenv('SMTP_TEST_PORT'),'security'=>'test_plain','username'=>'','password'=>'TICKET_MAIL_PRIVATE_PASSWORD','from'=>'shop@example.test','from_name'=>'工单隔离验收']];
$settings->configure($admin,$config);$mail=$settings->load();
foreach([['enabled'=>true,'admin_ids'=>[]],['enabled'=>'true','admin_ids'=>[$admin]],['enabled'=>true,'admin_ids'=>[$admin,$admin]],['enabled'=>true,'admin_ids'=>[9999999]],['enabled'=>true,'admin_ids'=>[$admin],'after_reply_id'=>0]] as $bad){tmReject(fn()=>$settings->configureTickets($admin,$bad),400,'invalid or caller-controlled subscription boundary rejected');}
$epoch=$settings->configureTickets($admin,['enabled'=>true,'admin_ids'=>[$admin,$support]]);
tmCheck(strlen($epoch['activation'])===32&&$epoch['activated_at']!=='','activation has a persisted server-created identity and UTC start');
$notifications->sweep();tmCheck(tmCount()===$count&&tmDeliveries($historical['reply_id'])===[],'sweep never backfills pre-activation messages');
tmState('normal');$input=tmCreate();$made=$tickets->create($user,$input);$id=$made['ticket_id'];$rows=tmDeliveries($made['reply_id']);
tmCheck(count($rows)===2&&array_column($rows,'admin_id')===[$admin,$support],'new ticket notifies exactly the configured administrators');
tmCheck(count(array_unique(array_column($rows,'fingerprint')))===2&&array_column($rows,'user_id')===[null,null],'reply ID and administrator identity separate each mail placeholder');
tmCheck(tmState()['data_attempts']===0,'ticket HTTP/domain transaction never calls SMTP');
$repeat=$tickets->create($user,$input);tmCheck($repeat['reply_id']===$made['reply_id']&&count(tmDeliveries($made['reply_id']))===2,'lost create response replay retains original placeholders');
$follow=tmBody();$r=$tickets->write($user,false,$id,$follow);tmCheck(count(tmDeliveries($r['reply_id']))===2,'customer follow-up notifies specified administrators');
$replyBody=tmBody();$a=$tickets->write($support,true,$id,$replyBody);$customer=tmDeliveries($a['reply_id']);
tmCheck(count($customer)===1&&(int)$customer[0]['user_id']===$user&&$customer[0]['admin_id']===null,'public admin response notifies the ticket customer only');
$tickets->write($support,true,$id,$replyBody);tmCheck(count(tmDeliveries($a['reply_id']))===1,'admin response replay does not duplicate mail');
foreach([$rows[0],$customer[0]] as $row){
 $claim=$notifications->claim((int)$row['id'],$mail);tmCheck($claim!==null,'current eligible source has one sender claim');
 $text=$claim['message']->text;$subject=$claim['message']->subject;
 tmCheck(!str_contains($text.$subject,'PRIVATE_')&&!str_contains($text.$subject,'TICKET_MAIL_PRIVATE_PASSWORD')&&!str_contains($text,'token='),'mail contains no title, body, SMTP secret or login token');
 tmCheck(str_contains($text,($row['admin_id']!==null?'/admin':'').'/tickets/'.$id.'?page=last')&&str_contains($text,'登录'),'mail links to the original protected conversation');
 $result=App\Plugins\Notifications\NotificationRegistry::installed()->get('mail')->send($claim['target'],$claim['message'],$mail['config']);
 tmCheck($result->status==='accepted'&&$notifications->finish((int)$row['id'],$result),'local SMTP accepts the minimal UTF-8 update notice');
 tmCheck($notifications->claim((int)$row['id'],$mail)===null,'sent event is never reclaimed');
}
tmCheck(tmState()['data_attempts']===2,'one SMTP DATA per explicitly claimed recipient');
$body=tmBody('【结单】客户关闭了工单。');$r=$tickets->write($user,false,$id,$body);tmCheck(count(tmDeliveries($r['reply_id']))===2,'ordinary message is classified by code path, never by Chinese closure wording');
$count=tmCount();$c=$tickets->write($user,false,$id,['request_key'=>tmKey(),'expected_version'=>$tickets->detail($user,false,$id)['version']],true);
tmCheck(tmCount()===$count&&tmDeliveries($c['reply_id'])===[],'customer closure record does not send ordinary reply mail');
$id2=$tickets->create($user,tmCreate())['ticket_id'];$count=tmCount();$c=$tickets->write($admin,true,$id2,['request_key'=>tmKey(),'expected_version'=>$tickets->detail($admin,true,$id2)['version']],true);
tmCheck(tmCount()===$count&&tmDeliveries($c['reply_id'])===[],'admin closure records and replays are not notification events');
// Both source and placeholder must roll back if the local queue write fails.
$snapshot=[$db->query('SELECT * FROM tickets ORDER BY id')->fetchAll(),$db->query('SELECT * FROM ticket_replies ORDER BY id')->fetchAll(),tmCount()];
$db->exec("CREATE TRIGGER ticket_mail_queue_fault BEFORE INSERT ON notification_deliveries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated queue fault'");
try{try{$tickets->create($user,tmCreate());throw new RuntimeException('Expected queue fault');}catch(PDOException){}}finally{$db->exec('DROP TRIGGER ticket_mail_queue_fault');}
tmCheck([$db->query('SELECT * FROM tickets ORDER BY id')->fetchAll(),$db->query('SELECT * FROM ticket_replies ORDER BY id')->fetchAll(),tmCount()]===$snapshot,'queue failure rolls back ticket and original message together');
$beforeConfig=tmValue("SELECT value FROM system_settings WHERE `key`='notify.ticket_mail'");$audit=(int)tmValue('SELECT COUNT(*) FROM admin_audit_logs');
$db->exec("CREATE TRIGGER ticket_mail_audit_fault BEFORE INSERT ON admin_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated config audit fault'");
try{try{$settings->configureTickets($admin,['enabled'=>false,'admin_ids'=>[]]);throw new RuntimeException('Expected audit fault');}catch(PDOException){}}finally{$db->exec('DROP TRIGGER ticket_mail_audit_fault');}
tmCheck(tmValue("SELECT value FROM system_settings WHERE `key`='notify.ticket_mail'")===$beforeConfig&&(int)tmValue('SELECT COUNT(*) FROM admin_audit_logs')===$audit,'subscription and activation audit roll back atomically');
// Enqueue first, then change each send-time guarantee.
foreach(['owner','admin_email','admin_disabled','recipient_removed','subscription_disabled','reactivated','global_disabled','source_type','source_author','customer_email','customer_disabled'] as $gate){
 $settings->configureTickets($admin,['enabled'=>true,'admin_ids'=>[$admin]]);$made=$tickets->create($user,tmCreate());$ticket=$made['ticket_id'];
 $forCustomer=str_starts_with($gate,'customer_');$source=$forCustomer?$tickets->write($support,true,$ticket,tmBody()):$made;$row=tmDeliveries($source['reply_id'])[0];
 if($gate==='owner'){$db->prepare('UPDATE tickets SET user_id=? WHERE id=?')->execute([$other,$ticket]);}
 elseif($gate==='admin_email'){$db->prepare("UPDATE admins SET email='changed-ticket-mail@example.test' WHERE id=?")->execute([$admin]);}
 elseif($gate==='admin_disabled'){$db->prepare("UPDATE admins SET status='disabled' WHERE id=?")->execute([$admin]);}
 elseif($gate==='recipient_removed'){$settings->configureTickets($admin,['enabled'=>true,'admin_ids'=>[$support]]);}
 elseif($gate==='subscription_disabled'){$settings->configureTickets($admin,['enabled'=>false,'admin_ids'=>[]]);}
 elseif($gate==='reactivated'){$settings->configureTickets($admin,['enabled'=>false,'admin_ids'=>[]]);$settings->configureTickets($admin,['enabled'=>true,'admin_ids'=>[$admin]]);}
 elseif($gate==='global_disabled'){$off=$config;$off['enabled']=false;$settings->configure($admin,$off);}
 elseif($gate==='source_type'){$db->prepare("UPDATE ticket_replies SET type='internal_note' WHERE id=?")->execute([$source['reply_id']]);}
 elseif($gate==='source_author'){$db->prepare('UPDATE ticket_replies SET user_id=? WHERE id=?')->execute([$other,$source['reply_id']]);}
 elseif($gate==='customer_email'){$db->prepare("UPDATE users SET email='changed-ticket-client@example.test' WHERE id=?")->execute([$user]);}
 else{$db->prepare("UPDATE users SET status='suspended' WHERE id=?")->execute([$user]);}
 tmCheck($notifications->claim((int)$row['id'],$mail)===null&&tmDelivery((int)$row['id'])['error_message']==='STALE_BUSINESS_CONTEXT','send-time source/recipient/configuration check: '.$gate);
 $db->prepare("UPDATE admins SET email='ticket-mail-root@example.test',status='active' WHERE id=?")->execute([$admin]);
 $db->prepare("UPDATE users SET email='ticket-mail-client@example.test',status='active' WHERE id=?")->execute([$user]);
 if($gate==='global_disabled'){$settings->configure($admin,$config);}
}
$settings->configureTickets($admin,['enabled'=>false,'admin_ids'=>[]]);$count=tmCount();$made=$tickets->create($user,tmCreate());
$settings->configureTickets($admin,['enabled'=>true,'admin_ids'=>[$admin]]);$notifications->sweep();tmCheck(tmCount()===$count&&tmDeliveries($made['reply_id'])===[],'re-enabling never queues messages from the disabled period');
// Source transaction reaches the old shared configuration before activation: no historical mail.
$settings->configureTickets($admin,['enabled'=>false,'admin_ids'=>[]]);$child=null;$made=null;
Database::transaction(function()use($tickets,$settings,$user,$admin,&$child,&$made):void{$made=$tickets->create($user,tmCreate());$child=tmSpawn(['op'=>'configure','actor'=>$admin,'body'=>['enabled'=>true,'admin_ids'=>[$admin]]]);tmWait($child);});
$active=tmDone($child);tmCheck($active['enabled']&&tmDeliveries($made['reply_id'])===[],'activation waits for an earlier source transaction, no ambiguous backfill');
// Configuration holds the boundary first: the later source waits and sees the committed new epoch.
$settings->configureTickets($admin,['enabled'=>false,'admin_ids'=>[]]);$child=null;
Database::transaction(function()use($db,$settings,$user,$admin,&$child):void{$db->query("SELECT value FROM system_settings WHERE `key`='notify.mail' FOR UPDATE")->fetch();$child=tmSpawn(['op'=>'create','actor'=>$user,'body'=>tmCreate()]);tmWait($child);$settings->configureTickets($admin,['enabled'=>true,'admin_ids'=>[$admin]]);});
$made=tmDone($child);tmCheck(count(tmDeliveries($made['reply_id']))===1,'source waiting behind activation queues with the newly committed boundary');
// Concurrent identical source commits retain one reply and its one recipient placeholder.
$input=tmCreate();$children=[tmSpawn(['op'=>'create','actor'=>$user,'body'=>$input]),tmSpawn(['op'=>'create','actor'=>$user,'body'=>$input])];$x=tmDone($children[0]);$y=tmDone($children[1]);
tmCheck($x['reply_id']===$y['reply_id']&&count(tmDeliveries($x['reply_id']))===1,'simultaneous duplicate source produces one recipient placeholder');
$parallel=$tickets->create($user,tmCreate());$children=[];
Database::transaction(function()use($db,$user,$support,$parallel,&$children):void{
 $s=$db->prepare('SELECT id FROM tickets WHERE id=? FOR UPDATE');$s->execute([$parallel['ticket_id']]);$s->fetch();
 $children=[tmSpawn(['op'=>'reply','actor'=>$user,'id'=>$parallel['ticket_id'],'body'=>tmBody('CONCURRENT_CUSTOMER')]),
   tmSpawn(['op'=>'reply','actor'=>$support,'admin'=>true,'id'=>$parallel['ticket_id'],'body'=>tmBody('CONCURRENT_ADMIN')])];
 foreach($children as $child){tmWait($child);}
});
$p=tmDone($children[0]);$q=tmDone($children[1]);
tmCheck($tickets->detail($user,false,$parallel['ticket_id'])['replies']['total']===3,'concurrent customer/admin replies survive recipient foreign-key contention');
tmCheck(count(tmDeliveries($p['reply_id']))===1&&count(tmDeliveries($q['reply_id']))===1,'both concurrent reply identities retain exactly their recipient placeholder');
$once=tmBody('ONE_REPLY_IDEMPOTENCY');$sent=$tickets->write($support,true,$parallel['ticket_id'],$once);$replayed=$tickets->write($support,true,$parallel['ticket_id'],$once);
tmCheck($sent['reply_id']===$replayed['reply_id']&&count(tmDeliveries($sent['reply_id']))===1,'reply response loss and original-key retry retain one mail event');
$work=tmDeliveries($x['reply_id'])[0];tmOnly((int)$work['id']);tmState('normal');$jobs=[];
for($j=0;$j<4;$j++){$p=proc_open([PHP_BINARY,__DIR__.'/notification_process.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));$jobs[]=[$p,$pipes];}
foreach($jobs as [$p,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);foreach($pipes as $pipe){fclose($pipe);}tmCheck(proc_close($p)===0&&str_contains($out,'processed'),'parallel sender exits normally');}
tmCheck(tmState()['data_attempts']===1&&tmDelivery((int)$work['id'])['status']==='success','four senders submit one SMTP DATA for the ticket recipient');
$made=$tickets->create($user,tmCreate());$unknown=(int)tmDeliveries($made['reply_id'])[0]['id'];tmOnly($unknown);tmState('lost_response');$notifications->runOnce();
tmCheck(tmDelivery($unknown)['status']==='pending_manual_check'&&tmState()['data_attempts']===1,'lost SMTP acceptance becomes persistent manual review');
$notifications->sweep();$notifications->runOnce();tmCheck(tmState()['data_attempts']===1,'sweep and sender never resend an unknown ticket mail');
$notifications->resolve($admin,$unknown,['status'=>'success','expected_status'=>'pending_manual_check','evidence'=>'Local SMTP fixture proves accepted DATA for this Message-ID']);
tmCheck(tmDelivery($unknown)['status']==='success'&&!$notifications->finish($unknown,new NotificationResult('rejected','LATE_RESULT')),'existing manual resolution fences a late sender without resend');
$made=$tickets->create($user,tmCreate());$crash=(int)tmDeliveries($made['reply_id'])[0]['id'];tmOnly($crash);tmState('normal');$claim=$notifications->claim($crash,$settings->load());
$db->prepare('UPDATE notification_deliveries SET updated_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 16 MINUTE) WHERE id=?')->execute([$crash]);
tmCheck($claim!==null&&$notifications->recover()===1&&tmDelivery($crash)['status']==='pending_manual_check','claimed ticket mail crash is quarantined');
$notifications->runOnce();tmCheck(tmState()['data_attempts']===0,'crashed ticket claim is never reclaimed');
$settings->configureTickets($admin,['enabled'=>false,'admin_ids'=>[]]);$config['enabled']=false;$settings->configure($admin,$config);
// Restore fixture identity/status timestamps before comparing immutable business facts.
foreach($before['users'] as $u){if((int)$u['id']===$user){$db->prepare('UPDATE users SET updated_at=? WHERE id=?')->execute([$u['updated_at'],$user]);}}
tmCheck(tmFacts()===$before,'ticket mail preserves all customer financial, resource, task and catalog facts');
$process=proc_open([PHP_BINARY,dirname(__DIR__).'/artisan','notifications:tickets',(string)$admin,'--no-interaction'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,dirname(__DIR__));
fwrite($pipes[0],json_encode(['enabled'=>false,'admin_ids'=>[]],JSON_THROW_ON_ERROR));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
tmCheck($exit===0&&($result=json_decode(trim($out),true))&&$result['enabled']===false&&strlen($result['activation'])===32,'real Artisan command accepts private stdin and returns the explicit activation');
tmCheck(count($db->query('SHOW TABLES')->fetchAll())===27,'no new tables or schema migration');
tmCheck(!str_contains(json_encode($db->query("SELECT value FROM system_settings WHERE `key`='notify.ticket_mail'")->fetchAll()),'TICKET_MAIL_PRIVATE_PASSWORD'),'subscription contains no SMTP secret');
echo 'Ticket mail checks: '.$checks."\n";
