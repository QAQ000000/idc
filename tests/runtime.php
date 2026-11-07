<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use App\Core\{Database, Security};
use App\Services\{BillingService, OutboxWorker};

if (config('app.env') !== 'test' || config('database.connections.mysql.database') !== 'jianyun_test') {
    throw new RuntimeException('Runtime fixtures require the isolated test database');
}
$db=Database::getInstance();
$mode=$argv[1]??'';
$s=$db->prepare("SELECT id FROM users WHERE email='runtime@example.test'");$s->execute();$uid=(int)$s->fetchColumn();
$s=$db->prepare("SELECT p.id FROM products p JOIN providers r ON r.id=p.provider_id WHERE r.endpoint=? AND r.driver='zjmf' ORDER BY p.id DESC LIMIT 1");$s->execute([getenv('UPSTREAM_TEST_URL')]);$pid=(int)$s->fetchColumn();
if (!$pid) { throw new RuntimeException('Isolated upstream fixture missing'); }
if ($mode==='seed') {
    $db->exec('UPDATE instances SET auto_renew=0');
    $db->prepare('INSERT INTO users(email,password,balance) VALUES(?,?,1.00)')->execute(['runtime@example.test',Security::hashPassword('RuntimeFixture!2026')]);$uid=(int)$db->lastInsertId();
    foreach ([true,false] as $automatic) {
        $r=(new BillingService())->checkout($uid,['product_id'=>$pid,'hostname'=>'runtime-fixture','password'=>'RuntimeFixture!2026','billing_cycle'=>'monthly','use_balance'=>true]);
        (new OutboxWorker())->runOnce('runtime-seed');
        $db->prepare('UPDATE instances SET due_date=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 2 DAY),auto_renew=? WHERE id=?')->execute([(int)$automatic,$r['instance_id']]);
    }
} elseif ($mode==='expire-lease') {
    $s=$db->prepare("UPDATE tasks t JOIN invoices v ON v.id=t.invoice_id SET t.lease_until=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND)
        WHERE t.id=? AND t.action='renew' AND t.status='running' AND v.user_id=? AND v.status='paid'");$s->execute([(int)($argv[2]??0),$uid]);
    if ($s->rowCount()!==1) { throw new RuntimeException('Expected runtime-owned crashed renewal'); }
} elseif ($mode==='renew') {
    $s=$db->prepare('SELECT id FROM instances WHERE user_id=? AND auto_renew=1 ORDER BY id LIMIT 1');$s->execute([$uid]);$iid=(int)$s->fetchColumn();
    $invoice=(new App\Services\RenewalService())->issue($uid,$iid);(new BillingService())->pay($uid,(int)$invoice['invoice_id']);
} elseif ($mode==='manual') {
    $s=$db->prepare("UPDATE tasks t JOIN invoices v ON v.id=t.invoice_id SET t.payload=JSON_SET(t.payload,'$.investigation_started_at',?),t.next_retry_at=NULL
        WHERE t.id=? AND t.action='renew' AND t.status='investigating' AND v.user_id=? AND v.status='paid'");$s->execute([time()-3601,(int)($argv[2]??0),$uid]);
    if ($s->rowCount()!==1) { throw new RuntimeException('Expected runtime-owned investigation'); }
} elseif ($mode==='expire-order') {
    $r=(new BillingService())->checkout($uid,['product_id'=>$pid,'hostname'=>'expire-runtime','password'=>'RuntimeFixture!2026','billing_cycle'=>'monthly']);
    $db->prepare('UPDATE invoices SET quote_expired_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE id=?')->execute([$r['invoice_id']]);
} elseif ($mode==='fail-expiration') {
    $db->exec("CREATE TRIGGER runtime_expiration_fault BEFORE UPDATE ON invoices FOR EACH ROW BEGIN IF NEW.status='cancelled' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated scheduler failure'; END IF; END");
} elseif ($mode==='clear-expiration-fault') {
    $db->exec('DROP TRIGGER runtime_expiration_fault');
} elseif ($mode!=='snapshot') { throw new RuntimeException('Unknown runtime fixture mode'); }
$s=$db->prepare('SELECT balance,total_spent FROM users WHERE id=?');$s->execute([$uid]);$out=$s->fetch();
$s=$db->prepare('SELECT id,status,remote_instance_id,due_date,auto_renew FROM instances WHERE user_id=? ORDER BY id');$s->execute([$uid]);$out['instances']=$s->fetchAll();
$s=$db->prepare('SELECT id,instance_id,type,status,cycle_marker FROM invoices WHERE user_id=? ORDER BY id');$s->execute([$uid]);$out['invoices']=$s->fetchAll();
$s=$db->prepare('SELECT t.id,t.invoice_id,t.action,t.status,t.execution_token,t.worker_id,t.lease_until,t.last_error FROM tasks t JOIN invoices v ON v.id=t.invoice_id WHERE v.user_id=? ORDER BY t.id');$s->execute([$uid]);$out['tasks']=$s->fetchAll();
$s=$db->prepare("SELECT COUNT(*) FROM transactions WHERE user_id=? AND type='refund_to_balance'");$s->execute([$uid]);$out['refunds']=(int)$s->fetchColumn();
echo json_encode($out,JSON_THROW_ON_ERROR),PHP_EOL;
