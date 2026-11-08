<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
set_exception_handler(static function(Throwable $e):never{fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
use App\Core\{Database,Security};
use App\Services\{AuthService,BillingService,CatalogService,DemoService,LedgerService};
if(config('app.env')!=='test'||config('database.connections.mysql.database')!=='jianyun_test')throw new RuntimeException('Isolated database required');
$db=Database::getInstance();$checks=0;
function bpCheck(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException('FAIL presentation: '.$why);$checks++;echo "PASS presentation: $why\n";}
function bpHttp(string $path,string $token):array{$c=curl_init(getenv('TEST_BASE_URL').'/api/v1'.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token]]);$raw=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);return [$status,json_decode($raw,true,64,JSON_THROW_ON_ERROR)];}
$auth=new AuthService();$session=$auth->register(['email'=>'billing-presentation@example.test','password'=>'PresentationCustomer12']);$uid=(int)$session['user']['id'];$token=$session['token'];
$other=$auth->register(['email'=>'billing-other@example.test','password'=>'PresentationOther12']);$otherId=(int)$other['user']['id'];
$demo=new DemoService();$pid=$demo->seed();$demo->recharge($uid,'100.00','presentation-wallet');$billing=new BillingService();
$quote=(new CatalogService())->calculate($pid,'monthly',[]);
$expiry=strtotime($quote['quote_expires_at'].' UTC');bpCheck($expiry>=time()+295&&$expiry<=time()+300,'quote publishes server UTC expiry without changing signature validity');
bpCheck((int)explode('.',$quote['quote_token'])[0]===$expiry,'public quote deadline matches signed token deadline');
$order=['product_id'=>$pid,'hostname'=>'presentation','password'=>'PresentationRoot12','billing_cycle'=>'monthly','use_balance'=>true];
$invoice=$billing->checkout($uid,$order);$id=(int)$invoice['invoice_id'];$instance=(int)$invoice['instance_id'];
bpCheck($invoice['status']==='paid'&&$invoice['fulfillment_status']==='provisioning','paid invoice independently reports pending fulfillment');
bpCheck($invoice['wallet_credit_total']==='0.00'&&$invoice['late_credit_total']==='0.00','wallet debit is not projected as incoming funds');
foreach(['running'=>'provisioning','investigating'=>'investigating','failed'=>'needs_review','success'=>'delivered'] as $status=>$expected){$db->prepare('UPDATE tasks SET status=? WHERE invoice_id=?')->execute([$status,$id]);bpCheck($billing->invoice($uid,$id)['fulfillment_status']===$expected,'original invoice task projects '.$status.' without guessing instance state');}
// A later unrelated operation must not override this invoice's completed create task.
$db->prepare("INSERT INTO tasks(instance_id,idempotency_key,action,status,payload) VALUES(?,'presentation-power','power','investigating','{}')")->execute([$instance]);
bpCheck($billing->invoice($uid,$id)['fulfillment_status']==='delivered','unrelated power investigation does not overwrite historical create result');
$db->prepare("INSERT INTO invoices(user_id,instance_id,type,total,status) VALUES(?,?,'renew','10.00','paid')")->execute([$uid,$instance]);$renew=(int)$db->lastInsertId();
$db->prepare("INSERT INTO tasks(instance_id,invoice_id,idempotency_key,action,status,payload) VALUES(?,?,'presentation-renew','renew','investigating','{}')")->execute([$instance,$renew]);
bpCheck($billing->invoice($uid,$renew)['fulfillment_status']==='investigating'&&$billing->invoice($uid,$id)['fulfillment_status']==='delivered','renewal result belongs to its own bill rather than latest instance task');
$db->prepare("UPDATE tasks SET status='success' WHERE invoice_id=?")->execute([$renew]);bpCheck($billing->invoice($uid,$renew)['fulfillment_status']==='renewed','completed renewal has its own confirmed result');
$db->prepare("INSERT INTO invoices(user_id,instance_id,type,total,status) VALUES(?,?,'new','10.00','paid')")->execute([$uid,$instance]);$legacy=(int)$db->lastInsertId();
bpCheck($billing->invoice($uid,$legacy)['fulfillment_status']==='needs_review','paid invoice without task requires verification instead of pretending delivery');
// Fixture with inconsistent ownership: never expose or trust another customer's task.
$db->prepare("INSERT INTO invoices(user_id,instance_id,type,total,status) VALUES(?,?,'new','10.00','paid')")->execute([$otherId,$instance]);$foreign=(int)$db->lastInsertId();
$db->prepare("INSERT INTO tasks(instance_id,invoice_id,idempotency_key,action,status,payload) VALUES(?,?,'presentation-foreign','create','success','{}')")->execute([$instance,$foreign]);
$foreignProjection=$billing->invoice($otherId,$foreign);bpCheck($foreignProjection['fulfillment_status']==='needs_review'&&$foreignProjection['instance_name']===null,'resource ownership guards read projection even with inconsistent legacy binding');
Database::transaction(function(PDO $db)use($uid,$otherId,$id):void{(new LedgerService())->wallet($db,$uid,$id,'2.00','late_payment_credit','presentation-late');(new LedgerService())->wallet($db,$uid,$id,'3.00','refund_to_balance','presentation-compensation');(new LedgerService())->wallet($db,$otherId,$id,'7.00','late_payment_credit','presentation-other-credit');});
$projection=bpHttp('/invoices/'.$id,$token)[1]['data'];
bpCheck($projection['wallet_credit_total']==='5.00'&&$projection['late_credit_total']==='2.00','wallet projection excludes other owners and separates compensation from late credit');
bpCheck($projection['status']==='paid'&&$projection['fulfillment_status']==='delivered','additional wallet credit does not rewrite original settlement or fulfillment');
bpCheck(!preg_match('/payload|last_error|execution_token|idempotency_key|encrypted_password|checkout_hash|provider_id|remote_instance_id/',json_encode($projection)),'client progress publishes no credentials, raw task errors or internal execution identity');
$list=bpHttp('/invoices?type=renew&status=paid',$token)[1]['data'];bpCheck($list['total']===1&&$list['items'][0]['invoice_id']===$renew&&$list['items'][0]['fulfillment_status']==='renewed','combined type and payment-state filter returns correct progress');
$list=bpHttp('/invoices?type=new',$token)[1]['data'];$rows=array_column($list['items'],null,'invoice_id');bpCheck($rows[$id]['wallet_credit_total']==='5.00'&&$rows[$legacy]['fulfillment_status']==='needs_review','list and detail share the same financial and progress projection');
$unpaid=$billing->checkout($uid,array_replace($order,['use_balance'=>false]));$db->prepare("UPDATE invoices SET quote_expired_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id=?")->execute([$unpaid['invoice_id']]);
$expired=$billing->invoice($uid,(int)$unpaid['invoice_id']);bpCheck((int)$expired['quote_expired']===1&&$expired['status']==='unpaid'&&$expired['fulfillment_status']===null,'expired unpaid quote remains separate from payment and fulfillment');
bpCheck(bpHttp('/invoices/'.$id,$other['token'])[0]===403,'customer cannot read another customer bill progress or incoming funds');
foreach(['type=invalid','type[]=new','status=provisioning'] as $query)bpCheck(bpHttp('/invoices?'.$query,$token)[0]===400,'reject invalid or mixed financial/progress filter: '.$query);
$snapshot=hash('sha256',json_encode($db->query('SELECT id,balance,total_spent FROM users ORDER BY id')->fetchAll()));$billing->listing($uid,[]);$billing->invoice($uid,$id);
bpCheck($snapshot===hash('sha256',json_encode($db->query('SELECT id,balance,total_spent FROM users ORDER BY id')->fetchAll())),'read projection does not change balances or consumption');
echo "Billing presentation checks: $checks\n";
