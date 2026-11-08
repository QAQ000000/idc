<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

if (config('app.env') !== 'test' || config('database.connections.mysql.database') !== 'jianyun_test') {
    throw new RuntimeException('Browser fixture requires the isolated test database');
}
$db = App\Core\Database::getInstance();
$db->prepare('INSERT INTO system_settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')
    ->execute(['captcha_scenes', json_encode(['login' => ['enabled' => false], 'register' => ['enabled' => false]])]);
$session = (new App\Services\AuthService())->register(['email' => 'browser@example.test', 'password' => 'BrowserFixture!2026']);
$demo = new App\Services\DemoService();
$demo->recharge((int)$session['user']['id'], '100.00', 'browser-fixture');
$product = $demo->seed();
echo json_encode(['product_id' => $product], JSON_THROW_ON_ERROR), PHP_EOL;

// Explicitly synthetic accounts and paid exceptions; never loaded into the M2 business database.
$admins = new App\Services\AdminAuthService();
$admins->create(['username'=>'browser-admin','email'=>'browser-admin@example.test','password'=>'BrowserAdmin!2026','role'=>'super_admin']);
$admins->create(['username'=>'browser-support','email'=>'browser-support@example.test','password'=>'BrowserSupport!2026','role'=>'support']);
$admins->create(['username'=>'browser-finance','email'=>'browser-finance@example.test','password'=>'BrowserFinance!2026','role'=>'finance']);
$billing = new App\Services\BillingService();
$taskIds=[];
foreach (['网页核验样本 A','网页核验样本 B'] as $label) {
    $invoice=$billing->checkout((int)$session['user']['id'],['product_id'=>$product,'hostname'=>'browser-host','password'=>'BrowserRoot!2026','billing_cycle'=>'monthly','use_balance'=>true]);
    $db->prepare("UPDATE tasks SET status='failed',execution_token=7,last_error='MANUAL_AUDIT_REQUIRED:NON_QUERYABLE',upstream_task_id='host:browser-fixture' WHERE invoice_id=?")->execute([$invoice['invoice_id']]);
    $db->prepare('UPDATE instances SET name=?,ip_address=?,remote_instance_id=? WHERE id=?')->execute([$label,'2001:db8:85a3:8d3:1319:8a2e:370:7348','host:browser-fixture-long-resource-'.$invoice['instance_id'],$invoice['instance_id']]);
    $s=$db->prepare('SELECT id FROM tasks WHERE invoice_id=?');$s->execute([$invoice['invoice_id']]);$taskIds[]=(int)$s->fetchColumn();
}
echo json_encode(['admin_fixture_tasks'=>$taskIds],JSON_THROW_ON_ERROR),PHP_EOL;

// Separate owner keeps the existing two-resource FPM identity fixture unchanged.
$reinstallOwner=(new App\Services\AuthService())->register(['email'=>'browser-reinstall@example.test','password'=>'BrowserReinstall!2026']);
$demo->recharge((int)$reinstallOwner['user']['id'],'1.00','browser-reinstall-fixture');
// One actual fixture-provider resource for destructive UI acceptance in the disposable environment only.
file_get_contents(getenv('UPSTREAM_TEST_URL').'/__power_mode?mode=normal');
file_get_contents(getenv('UPSTREAM_TEST_URL').'/__reinstall_mode?mode=normal');
$db->prepare('INSERT INTO providers(name,driver,endpoint,credentials) VALUES(?,?,?,?)')->execute(['Browser reinstall fixture','zjmf',getenv('UPSTREAM_TEST_URL'),json_encode(['username'=>App\Core\Security::encrypt('fixture'),'password'=>App\Core\Security::encrypt('fixture-secret')])]);
$provider=(int)$db->lastInsertId();
$pid=(new App\Services\ProductSnapshotService())->save(['provider_id'=>$provider,'remote_pid'=>391,'name'=>'重装网页验收商品','type'=>'cloud','billing_cycles'=>['monthly']])['product_id'];
$invoice=$billing->checkout((int)$reinstallOwner['user']['id'],['product_id'=>$pid,'hostname'=>'BrowserReinstall','password'=>'BrowserRoot!2026','billing_cycle'=>'monthly','use_balance'=>true]);
(new App\Services\OutboxWorker())->runOnce('browser-reinstall-create');
echo json_encode(['reinstall_fixture_instance'=>$invoice['instance_id']],JSON_THROW_ON_ERROR),PHP_EOL;
