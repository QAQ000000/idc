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
