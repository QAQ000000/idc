<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use App\Core\{ApiException,Database,Security};
use App\Services\{AdminAccountService,AdminAuthService,AuthService,SiteSettingsService};
if(config('app.env')!=='test'||config('database.connections.mysql.database')!=='jianyun_test'){throw new RuntimeException('Isolated DB required');}
set_exception_handler(static function(Throwable $e):never{fwrite(STDERR,(string)$e.PHP_EOL);exit(1);});
$db=Database::getInstance();$service=new SiteSettingsService();$checks=0;
if(($argv[1]??'')==='child'){
    $v=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);echo 'READY:'.$db->query('SELECT CONNECTION_ID()')->fetchColumn()."\n";fflush(STDOUT);
    try{$service->save($v['actor'],$v['session'],$v['body']);echo json_encode(['status'=>200]);}catch(ApiException $e){echo json_encode(['status'=>$e->httpStatus]);}exit;
}
function ssCheck(bool $v,string $why):void{global $checks;if(!$v){throw new RuntimeException('FAIL site settings: '.$why);}++$checks;echo 'PASS site settings: '.$why.PHP_EOL;}
function ssHttp(string $path,?string $token=null,string $method='GET',?array $body=null):array{
    $h=curl_init(getenv('TEST_BASE_URL').'/api/v1'.$path);$cache='';
    curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>array_filter(['Content-Type: application/json',$token?'Authorization: Bearer '.$token:null]),CURLOPT_HEADERFUNCTION=>static function($h,$line)use(&$cache){if(str_starts_with(strtolower($line),'cache-control:')){$cache=trim(substr($line,14));}return strlen($line);}]);
    if($body!==null){curl_setopt($h,CURLOPT_POSTFIELDS,json_encode($body?:new stdClass(),JSON_THROW_ON_ERROR));}$raw=curl_exec($h);$code=curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);return [$code,json_decode($raw,true,64,JSON_THROW_ON_ERROR),$cache];
}
function ssShow():array{global $service,$actor;return $service->show($actor,AdminAuthService::sessionVersion(Database::getInstance(),$actor));}
function ssBody(?array $value=null):array{$v=$value??ssShow();return array_intersect_key($v,array_flip(['site_name','logo_path','company_name','copyright_text','icp_number']))+['expected_version'=>$v['version']];}
function ssSave(array $body):array{global $token;return ssHttp('/admin/site-settings',$token,'PATCH',$body);}
function ssSpawn(array $input):array{
    $p=proc_open([PHP_BINARY,__FILE__,'child'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);fwrite($pipes[0],json_encode($input,JSON_THROW_ON_ERROR));fclose($pipes[0]);stream_set_timeout($pipes[1],15);$line=trim(fgets($pipes[1])?:'');if(!preg_match('/^READY:(\d+)$/D',$line,$m)){throw new RuntimeException('Child not ready');}return [$p,$pipes,(int)$m[1]];
}
function ssWait(array $child):void{
    global $db;$until=microtime(true)+8;
    do{$s=$db->prepare('SELECT trx_state FROM information_schema.innodb_trx WHERE trx_mysql_thread_id=?');$s->execute([$child[2]]);if($s->fetchColumn()==='LOCK WAIT'){return;}usleep(100000);}while(microtime(true)<$until);throw new RuntimeException('No real lock wait observed');
}
function ssFinish(array $c):int{[$p,$pipes]=$c;$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0){throw new RuntimeException($out.$err);}return json_decode($out,true,32,JSON_THROW_ON_ERROR)['status'];}

$auth=new AdminAuthService();$actor=$auth->create(['username'=>'site-settings-super','email'=>'site-settings-super@example.test','password'=>'SiteSettings!2026','role'=>'super_admin']);$token=Security::generateToken(['uid'=>$actor,'sub_type'=>'admin']);
$second=$auth->create(['username'=>'site-settings-second','email'=>'site-settings-second@example.test','password'=>'SiteSettings!2026','role'=>'super_admin']);
$client=(new AuthService())->register(['email'=>'site-settings-client@example.test','password'=>'SiteSettings!2026']);
foreach([null,$client['token']] as $t){foreach(['GET','PATCH'] as $method){ssCheck(ssHttp('/admin/site-settings',$t,$method,$method==='PATCH'?[]:null)[0]===401,'anonymous/client cannot '.$method.' admin settings');}}
foreach(['finance','support','operator'] as $role){$id=$auth->create(['username'=>'site-settings-'.$role,'email'=>'site-settings-'.$role.'@example.test','password'=>'SiteSettings!2026','role'=>$role]);$t=Security::generateToken(['uid'=>$id,'sub_type'=>'admin']);foreach(['GET','PATCH'] as $method){ssCheck(ssHttp('/admin/site-settings',$t,$method,$method==='PATCH'?[]:null)[0]===403,$role.' cannot '.$method.' site settings');}}
[$code,$public,$cache]=ssHttp('/site');$defaults=$public['data'];ssCheck($code===200&&$defaults===['site_name'=>'简云','logo_path'=>'','company_name'=>'','copyright_text'=>'让云资源管理更简单','icp_number'=>'']&&str_contains($cache,'no-store'),'anonymous exact whitelist and defaults, no-store');
ssCheck(!ssShow()['configured']&&strlen(ssShow()['version'])===64,'unconfigured admin receives version without seed write');
$other=$db->query("SELECT `key`,value,encrypted_credentials,updated_by FROM system_settings WHERE `key`<>'site.basic' ORDER BY `key`")->fetchAll();
$body=array_replace(ssBody(),['site_name'=>'验收云','logo_path'=>'/images/logo.svg','company_name'=>'验收有限公司','copyright_text'=>'© 2026 验收云','icp_number'=>'沪ICP备12345678号']);
ssCheck(ssSave($body)[0]===200,'first configuration saves without migration');
ssCheck(ssHttp('/site')[1]['data']===array_diff_key($body,['expected_version'=>true]),'all five public fields read from committed configuration');
ssCheck($db->query("SELECT `key`,value,encrypted_credentials,updated_by FROM system_settings WHERE `key`<>'site.basic' ORDER BY `key`")->fetchAll()===$other,'save leaves SMTP/payment/captcha/other settings exactly unchanged');
ssCheck($db->query("SELECT encrypted_credentials FROM system_settings WHERE `key`='site.basic'")->fetchColumn()===null,'site branding stores no encrypted credentials');
ssCheck(ssSave($body)[0]===409,'stale and repeated save rejected');
[$code,$admin,$cache]=ssHttp('/admin/site-settings',$token);ssCheck($code===200&&str_contains($cache,'no-store')&&count($admin['data'])===7,'admin output only public fields, configured flag and version');
$audit=$db->query("SELECT context FROM admin_audit_logs WHERE action='site_configure' ORDER BY id DESC LIMIT 1")->fetchColumn();$audit=json_decode($audit,true,32,JSON_THROW_ON_ERROR);ssCheck($audit['before']==$defaults&&$audit['after']==ssHttp('/site')[1]['data'],'immutable audit records before/after public configuration');
foreach(['https://supplier.example/logo.png','//supplier.example/a.png','/../a.png','/images/../a.png','/images/%2e%2e/a.png','/images/a.png?token=secret','/images/a.png#x','data:image/png;base64,AAAA','/api/v1/site','/images/a.php','/images/a.PNG',"/images\\a.png",'/images/a.b.png'] as $logo){ssCheck(ssSave(array_replace(ssBody(),['logo_path'=>$logo]))[0]===400,'reject unsafe/nonstatic logo path '.$logo);}
foreach([['site_name'=>[]],['company_name'=>true],['copyright_text'=>null],['icp_number'=>123],['site_name'=>str_repeat('名',81)],['company_name'=>str_repeat('名',121)],['copyright_text'=>str_repeat('字',201)],['icp_number'=>str_repeat('字',81)],['copyright_text'=>"text\nline"],['key'=>'DO_NOT_EXPOSE'],['smtp'=>['password'=>'SECRET']],['expected_version'=>'x']] as $bad){ssCheck(ssSave(array_replace(ssBody(),$bad))[0]===400,'invalid/extra field rejected');}
$missing=ssBody();unset($missing['company_name']);ssCheck(ssSave($missing)[0]===400,'partial writes rejected, no accidental overwrite');
$state=ssShow();$count=(int)$db->query("SELECT COUNT(*) FROM admin_audit_logs WHERE action='site_configure'")->fetchColumn();
$db->exec("CREATE TRIGGER site_settings_audit_failure BEFORE INSERT ON admin_audit_logs FOR EACH ROW BEGIN IF NEW.action='site_configure' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='site audit unavailable'; END IF; END");
try{ssCheck(ssSave(array_replace(ssBody(),['site_name'=>'不得保存']))[0]===500,'audit failure rejects transaction');}finally{$db->exec('DROP TRIGGER site_settings_audit_failure');}
ssCheck(ssShow()===$state&&(int)$db->query("SELECT COUNT(*) FROM admin_audit_logs WHERE action='site_configure'")->fetchColumn()===$count,'audit fault rolls back value and version');
// A real settings lock fixes the concurrency ordering; the second admin must read the latest CAS.
$body=ssBody();$body['site_name']='并发先提交';
$child=Database::transaction(function(PDO $db)use($body,$second,$service,$actor):array{
    // Acquire through the service: admin/session -> settings -> site audit, never invert the production order.
    $service->show($actor,0);
    $c=ssSpawn(['actor'=>$second,'session'=>0,'body'=>array_replace($body,['site_name'=>'旧版不得覆盖'])]);ssWait($c);
    $service->save($actor,0,$body);return $c;
});
ssCheck(ssFinish($child)===409&&ssShow()['site_name']==='并发先提交','two admins same version: waiting write rejected after real row lock');
// Same content must still advance the version and fence delayed requests (ABA).
$a=ssBody();ssCheck(ssSave($a)[0]===200&&ssShow()['version']!==$a['expected_version'],'same-value save advances audit-backed version');ssCheck(ssSave($a)[0]===409,'old request cannot revive after same-value save');
$child=Database::transaction(function(PDO $db)use($second,$service,$actor):array{
    $db->prepare('SELECT id FROM admins WHERE id=? FOR UPDATE')->execute([$second]);
    $c=ssSpawn(['actor'=>$second,'session'=>0,'body'=>ssBody()]);ssWait($c);
    $s=new AdminAccountService();$s->update($actor,0,$second,['role'=>'support','status'=>'active','expected_version'=>$s->detail($actor,0,$second)['account']['version'],'evidence'=>'SITE ACCEPTANCE: revoke waiting administrator']);return $c;
});
ssCheck(ssFinish($child)===401,'waiting actor rechecked for current session after revocation');
// Deliberately corrupt only this isolated branding JSON: output still projects five safe fields.
$contaminated=['site_name'=>'污染测试','logo_path'=>'https://private.example/logo.svg','company_name'=>['secret'=>'NEVER_PUBLIC'],'copyright_text'=>false,'icp_number'=>'','smtp_password'=>'NEVER_PUBLIC','payment'=>['key'=>'NEVER_PUBLIC']];
$db->prepare("UPDATE system_settings SET value=? WHERE `key`='site.basic'")->execute([json_encode($contaminated,JSON_THROW_ON_ERROR)]);
$visible=ssHttp('/site')[1]['data'];ssCheck(count($visible)===5&&$visible['logo_path']===''&&$visible['company_name']===''&&$visible['copyright_text']===$defaults['copyright_text']&&!str_contains(json_encode($visible),'NEVER_PUBLIC'),'malformed values and injected secret keys excluded field by field');
ssCheck(ssSave(array_replace(ssBody(),array_fill_keys(array_keys($defaults),'')))[0]===200&&ssHttp('/site')[1]['data']===array_fill_keys(array_keys($defaults),''),'explicit empty values accepted; frontend applies display defaults');
// Leave browser fixture with its original default site, without touching any other settings.
ssCheck(ssSave(array_replace(ssBody(),$defaults))[0]===200,'restore only isolated branding defaults');
echo 'Site settings checks: '.$checks.PHP_EOL;
