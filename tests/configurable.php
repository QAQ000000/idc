<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use App\Core\{ApiException,Database,Security,Money};
use App\Services\{CatalogService,BillingService,ProductSnapshotService,OutboxWorker};
use App\Plugins\Providers\{ConfigOptions,ProviderRegistry};
if (getenv('APP_ENV')!=='test' || getenv('DB_DATABASE')!=='jianyun_test') { throw new RuntimeException('Isolated DB required'); }
$db=Database::getInstance();$checks=0;$upstream=getenv('UPSTREAM_TEST_URL');
function check(bool $v,string $m):void {global $checks;if(!$v){throw new RuntimeException('FAIL CONFIG: '.$m);} $checks++;echo "PASS CONFIG: $m\n";}
function reject(callable $fn,string $m):void {try{$fn();}catch(ApiException){check(true,$m);return;}throw new RuntimeException('Expected rejection: '.$m);}
function configValue(string $sql,array $args=[]):mixed {global $db;$s=$db->prepare($sql);$s->execute($args);return $s->fetchColumn();}
function mode(string $mode):void {global $upstream;file_get_contents($upstream.'/__config_mode?mode='.$mode);}
function state():array {global $upstream;return json_decode(file_get_contents($upstream.'/__state'),true,64,JSON_THROW_ON_ERROR);}
check(Money::markup('0.05','percent','10.00')==='0.06','decimal half-up at half cent');
check(Money::markup('16.00','fixed','2.35')==='18.35' && Money::markup('16.00','none','0.00')==='16.00','fixed and zero markup');
reject(fn()=>Money::markup('99999999.99','percent','10.00'),'markup overflow rejected before SQL');
$provider=(int)configValue("SELECT id FROM providers WHERE driver='zjmf' AND endpoint=? LIMIT 1",[$upstream]);
$input=['provider_id'=>$provider,'remote_pid'=>14,'name'=>'Configurable product protocol fixture','type'=>'cloud','markup_type'=>'percent','markup_value'=>'10.00'];
$saved=(new ProductSnapshotService)->save($input);$pid=$saved['product_id'];$catalog=new CatalogService;$billing=new BillingService;
$form=$catalog->configuration($pid);$options=[];foreach($form['fields'] as $f){$options[$f['id']]=$f['default'];}
check(count($form['fields'])===13 && $saved['prices']['monthly']==='17.60','real product 14 shape imports into one JSON snapshot with reference price');
check(!str_contains(json_encode($form),'upstream_id') && !str_contains(json_encode($form),'pricings'),'public form projects only renderable data');
$q=$catalog->calculate($pid,'monthly',$options);
check($q['total']==='17.60' && $q['recurring_price']==='15.40','setup fee is excluded from recurring price before markup');
check($q['password_policy']===['min_length'=>12,'max_length'=>20,'character_sets'=>['upper','lower','digit'],'generation_length'=>12],'quote exposes upstream password generation rules before payment');
$changed=$options;$changed['276853']='1173031';$changedQuote=$catalog->calculate($pid,'monthly',$changed);
check($changedQuote['total']==='26.40' && $changedQuote['recurring_price']==='24.20','selected CPU changes server-calculated prices');
reject(fn()=>$catalog->calculate($pid,'monthly',$options+['999'=>'1']),'unknown option cannot be silently ignored');
$missing=$options;unset($missing['276851']);reject(fn()=>$catalog->calculate($pid,'monthly',$missing),'all required options must be selected');
$bad=$options;$bad['276851']=['nested'=>'payload'];reject(fn()=>$catalog->calculate($pid,'monthly',$bad),'nested option injection rejected');
$bad=$options;$bad['276851']='1173031';reject(fn()=>$catalog->calculate($pid,'monthly',$bad),'value from another field rejected');
$driver=ProviderRegistry::installed()->get('zjmf');$raw=json_decode(file_get_contents(__DIR__.'/fixtures/zjmf-product-14.json'),true);
foreach (['advanced','customfields'] as $field) { $unsupported=$raw;$unsupported[$field]=[['id'=>1]];try{$driver->describeForm($unsupported);throw new LogicException('Unsupported accepted');}catch(RuntimeException){check(true,'unsupported '.$field.' fails closed');} }
$db->prepare('INSERT INTO users(email,password,balance) VALUES(?,?,1000)')->execute(['config@example.test',Security::hashPassword('ConfigPassword!22')]);$user=(int)$db->lastInsertId();
$order=['product_id'=>$pid,'billing_cycle'=>'monthly','hostname'=>'config-host','password'=>'ConfigPassword22','config_options'=>$options,'checkout_key'=>'config-checkout-0001','use_balance'=>true];
$before=configValue('SELECT balance FROM users WHERE id=?',[$user]);
foreach ([21,28] as $length) {
    $tooLong=$order;$tooLong['password']=str_repeat('A',$length);$tooLong['quote_token']=$q['quote_token'];
    reject(fn()=>$billing->checkout($user,$tooLong),'overlong password rejected before checkout at '.$length.' bytes');
}
foreach (['ConfigPassword!22','configpassword22','CONFIGPASSWORD22','ConfigPasswordxx'] as $password) {
    $invalid=$order;$invalid['password']=$password;$invalid['quote_token']=$q['quote_token'];
    reject(fn()=>$billing->checkout($user,$invalid),'password must follow upstream character sets without silently changing input');
}
check(configValue('SELECT balance FROM users WHERE id=?',[$user])===$before
    && (int)configValue('SELECT COUNT(*) FROM invoices WHERE user_id=?',[$user])===0
    && (int)configValue('SELECT COUNT(*) FROM instances WHERE user_id=?',[$user])===0,'password rejection creates no instance, invoice, debit or Outbox');
reject(fn()=>$billing->checkout($user,$order),'configurable checkout requires confirmed quote');
$badToken=$order+['quote_token'=>$q['quote_token'].'0'];reject(fn()=>$billing->checkout($user,$badToken),'tampered quote rejected');
mode('price');reject(fn()=>$billing->checkout($user,$order+['quote_token'=>$q['quote_token']]),'price change between calculate and checkout requires confirmation');
check(configValue('SELECT balance FROM users WHERE id=?',[$user])===$before && (int)configValue('SELECT COUNT(*) FROM invoices WHERE user_id=?',[$user])===0,'stale price creates no invoice, debit or Outbox');
mode('recurring');reject(fn()=>$billing->checkout($user,$order+['quote_token'=>$q['quote_token']]),'renewal price change also invalidates confirmed quote');
mode('schema');reject(fn()=>$catalog->calculate($pid,'monthly',$options),'changed upstream form requires snapshot refresh');
mode('input_rules');reject(fn()=>$billing->checkout($user,$order+['quote_token'=>$q['quote_token']]),'generation rule changes invalidate the confirmed quote');
mode('normal');
$pf=$catalog->preflight($pid,'monthly',$options);$db->prepare('UPDATE products SET markup_value=11 WHERE id=?')->execute([$pid]);
reject(fn()=>$catalog->quote($db,$pid,'monthly',$options,true,$pf),'row-lock phase detects local price policy change');
$db->prepare('UPDATE products SET markup_value=10 WHERE id=?')->execute([$pid]);
$order['quote_token']=$catalog->calculate($pid,'monthly',$options)['quote_token'];$invoice=$billing->checkout($user,$order);
check($invoice['status']==='paid' && $invoice['total']==='17.60' && $invoice['recurring_price']==='15.40','paid checkout freezes first and renewal prices independently');
$snapshot=json_decode(configValue('SELECT config_snapshot FROM instances WHERE id=?',[$invoice['instance_id']]),true);
check($snapshot['upstream_total']==='16.00' && $snapshot['upstream_recurring']==='14.00' && $snapshot['config_options']===ConfigOptions::canonical($options),'server freezes upstream costs and canonical configuration');
check($snapshot['driver_context']['hostname_policy']==['prefix'=>'ser','length'=>12,'character_sets'=>['digit']],'hostname generation rules freeze from upstream product schema independent of MySQL JSON key order');
check(configValue('SELECT recurring_price FROM instances WHERE id=?',[$invoice['instance_id']])==='15.40','renewal service will bill instance recurring price');
mode('unavailable');$replay=$order;$replay['config_options']=array_reverse($options,true);$replay['quote_token']='expired';
check($billing->checkout($user,$replay)['invoice_id']===$invoice['invoice_id'],'semantic duplicate replays before quote/upstream checks');
$replay['config_options']=$changed;reject(fn()=>$billing->checkout($user,$replay),'same checkout key cannot buy different configuration');
check((int)configValue('SELECT COUNT(*) FROM tasks WHERE invoice_id=?',[$invoice['invoice_id']])===1 && configValue('SELECT balance FROM users WHERE id=?',[$user])===bcsub($before,'17.60',2),'duplicate request retains one debit and one Outbox');
mode('normal');$db->exec("UPDATE tasks SET status='success',lease_until=NULL WHERE status IN ('pending','running','investigating') AND invoice_id<>".(int)$invoice['invoice_id']);
(new OutboxWorker)->runOnce('config-worker');
check(configValue('SELECT status FROM instances WHERE id=?',[$invoice['instance_id']])==='active','configurable protocol delivers through existing worker');
check(ConfigOptions::canonical(state()['last_config'])===ConfigOptions::canonical($options),'settle receives every frozen option using plural configoptions');
$oldSnapshot=configValue('SELECT price_snapshot FROM invoices WHERE id=?',[$invoice['invoice_id']]);
$input['product_id']=$pid;$input['markup_type']='fixed';$input['markup_value']='2.00';(new ProductSnapshotService)->save($input);
check(configValue('SELECT price_snapshot FROM invoices WHERE id=?',[$invoice['invoice_id']])===$oldSnapshot && configValue('SELECT recurring_price FROM instances WHERE id=?',[$invoice['instance_id']])==='15.40','snapshot refresh never reprices existing invoices or resources');
$renewal=(new \App\Services\RenewalService)->issue($user,(int)$invoice['instance_id']);
check($renewal['total']==='15.40','configurable renewal retains the original recurring retail price after catalog markup refresh');
$renewalSnapshot=json_decode(configValue('SELECT price_snapshot FROM invoices WHERE id=?',[$renewal['invoice_id']]),true);
check($renewalSnapshot['upstream_total']==='14.00','renewal preflight uses recurring upstream cost without setup fee');
$billing->pay($user,(int)$renewal['invoice_id']);(new OutboxWorker)->runOnce('config-renewal-worker');
check(configValue('SELECT status FROM tasks WHERE invoice_id=?',[$renewal['invoice_id']])==='success','configurable instance renews through the unchanged renewal worker');
$input['remote_pid']=391;reject(fn()=>(new ProductSnapshotService)->save($input),'refresh cannot replace resource ownership');
// Configuration identity must survive a lost create response without a second purchase.
$q=$catalog->calculate($pid,'monthly',$changed);$lost=$order;$lost['config_options']=$changed;$lost['checkout_key']='config-lost-response-0001';$lost['quote_token']=$q['quote_token'];
$lost['password']='BoundaryPassword1234'; // exactly 20 bytes
$lostInvoice=$billing->checkout($user,$lost);$creates=state()['creates'];
file_get_contents($upstream.'/__mode?mode=drop_after_create');
(new OutboxWorker)->runOnce('config-drop-response');
check(configValue('SELECT status FROM tasks WHERE invoice_id=?',[$lostInvoice['invoice_id']])==='investigating','configurable create response loss enters investigation');
// A submitted historical order must still be investigated, even if its stored password is now invalid.
$db->prepare('UPDATE instances SET encrypted_password=? WHERE id=?')->execute([Security::encrypt(str_repeat('X',28)),$lostInvoice['instance_id']]);
$db->prepare('UPDATE tasks SET next_retry_at=NULL WHERE invoice_id=?')->execute([$lostInvoice['invoice_id']]);
(new OutboxWorker)->runOnce('config-recover-response');
check(state()['creates']===1 && configValue('SELECT status FROM instances WHERE id=?',[$lostInvoice['instance_id']])==='active','lost configurable create recovers exact resource without repurchase');
check(ConfigOptions::canonical(state()['last_config'])===ConfigOptions::canonical($changed),'response loss retains the exact changed configuration');
file_get_contents($upstream.'/__mode?mode=normal');
// A new paid order whose upstream changes must stop before any second purchase.
$q=$catalog->calculate($pid,'monthly',$options);$next=$order;$next['checkout_key']='config-checkout-0002';$next['quote_token']=$q['quote_token'];$i2=$billing->checkout($user,$next);$creates=state()['creates'];mode('schema');(new OutboxWorker)->runOnce('changed-schema-worker');
check(state()['creates']===$creates && configValue('SELECT status FROM instances WHERE id=?',[$i2['instance_id']])==='pending','changed form after payment stops before upstream purchase');
check(str_starts_with((string)configValue('SELECT last_error FROM tasks WHERE invoice_id=?',[$i2['invoice_id']]),'MANUAL_AUDIT_REQUIRED:'),'paid delivery rejection remains visible to operators');
mode('normal');
// Concurrent HTTP clients exercise the real route, user mutex and quote confirmation together.
$parallelOrder=$order;$parallelOrder['checkout_key']='config-concurrent-0003';
$parallelOrder['quote_token']=$catalog->calculate($pid,'monthly',$options)['quote_token'];
$token=Security::generateToken(['uid'=>$user]);$multi=curl_multi_init();$handles=[];
$balanceBefore=configValue('SELECT balance FROM users WHERE id=?',[$user]);
for($n=0;$n<6;$n++) {
    $payload=$parallelOrder;if($n%2){$payload['config_options']=array_map('intval',array_reverse($options,true));}
    $c=curl_init(getenv('TEST_BASE_URL').'/api/v1/orders/checkout');
    curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$token],CURLOPT_POSTFIELDS=>json_encode($payload)]);
    curl_multi_add_handle($multi,$c);$handles[]=$c;
}
do {curl_multi_exec($multi,$running);if($running){curl_multi_select($multi,1);}} while($running);
$ids=[];$success=true;
foreach($handles as $c){$r=json_decode(curl_multi_getcontent($c),true);$success=$success && curl_getinfo($c,CURLINFO_RESPONSE_CODE)===200;$ids[]=$r['data']['invoice_id']??null;curl_multi_remove_handle($multi,$c);curl_close($c);}curl_multi_close($multi);
check($success && count(array_unique($ids))===1 && $ids[0]!==null,'six concurrent semantic duplicate HTTP requests return one invoice');
check(configValue('SELECT balance FROM users WHERE id=?',[$user])===bcsub($balanceBefore,'18.00',2) && (int)configValue('SELECT COUNT(*) FROM tasks WHERE invoice_id=?',[$ids[0]])===1,'concurrent configurable purchase debits and enqueues exactly once');
$summary=$billing->invoice($user,(int)$ids[0]);
check(count($summary['configuration'])===13 && $summary['configuration'][2]['value']==='1核','invoice retains readable selected configuration');
$public=(new \App\Services\InstanceService)->detail($user,(int)$summary['instance_id']);
check(count($public['configuration'])===13 && !isset($public['config_snapshot']),'instance exposes only safe configuration summary');

(new OutboxWorker)->runOnce('config-concurrent-delivery');
check(configValue('SELECT status FROM tasks WHERE invoice_id=?',[$ids[0]])==='success','concurrent purchase remains deliverable as one task');
// Historical paid-but-unsubmitted work must be stopped before settle, never truncated or repurchased.
$legacy=$order;$legacy['checkout_key']='config-legacy-password-0004';$legacy['quote_token']=$catalog->calculate($pid,'monthly',$options)['quote_token'];
$legacyInvoice=$billing->checkout($user,$legacy);$creates=state()['creates'];
$db->prepare('UPDATE instances SET encrypted_password=? WHERE id=?')->execute([Security::encrypt(str_repeat('X',28)),$legacyInvoice['instance_id']]);
(new OutboxWorker)->runOnce('config-legacy-password');
check(state()['creates']===$creates && str_contains((string)configValue('SELECT last_error FROM tasks WHERE invoice_id=?',[$legacyInvoice['invoice_id']]),'INVALID_INITIAL_PASSWORD'),'worker stops historical overlong password before upstream mutation');
check(configValue('SELECT status FROM invoices WHERE id=?',[$legacyInvoice['invoice_id']])==='paid' && configValue('SELECT status FROM instances WHERE id=?',[$legacyInvoice['instance_id']])==='pending','password rejection preserves paid funds and pending resource for administrator review');
$changedRules=$order;$changedRules['checkout_key']='config-changed-input-rules-0005';$changedRules['quote_token']=$catalog->calculate($pid,'monthly',$options)['quote_token'];
$changedRulesInvoice=$billing->checkout($user,$changedRules);$creates=state()['creates'];mode('input_rules');
(new OutboxWorker)->runOnce('config-changed-input-rules');
check(state()['creates']===$creates && str_contains((string)configValue('SELECT last_error FROM tasks WHERE invoice_id=?',[$changedRulesInvoice['invoice_id']]),'INPUT_RULES_CHANGED_BEFORE_EXECUTION'),'rule change after payment stops before remote order or payment');
mode('normal');
echo "$checks configurable checks passed.\n";
