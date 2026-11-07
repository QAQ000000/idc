<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

use App\Core\{ApiException, Database, Money, RedisStore, Security};
use App\Services\{DemoService, LedgerService};

if (getenv('APP_ENV') !== 'test' || !preg_match('/^jianyun_test(?:_[a-z0-9]+)?$/D', getenv('DB_DATABASE') ?: '')) {
    fwrite(STDERR, "Integration tests require APP_ENV=test and an isolated jianyun_test[_suffix] database.\n");
    exit(1);
}
$base = getenv('TEST_BASE_URL');
if (!$base || !preg_match('#^http://127\.0\.0\.1:[0-9]+$#D', $base)) {
    throw new RuntimeException('TEST_BASE_URL must point at a dedicated local HTTP test server');
}
$db = Database::getInstance();
$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $checks++;
    echo "PASS: {$message}\n";
}
function http(string $method, string $path, ?array $body = null, ?string $token = null): array
{
    global $base;
    $curl = handle($method, $base . $path, $body, $token);
    $raw = curl_exec($curl);
    if ($raw === false) {
        throw new RuntimeException('HTTP transport failed');
    }
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    return [$status, json_decode($raw, true, 32, JSON_THROW_ON_ERROR)];
}
function handle(string $method, string $url, ?array $body, ?string $token)
{
    $curl = curl_init($url);
    $headers = ['Content-Type: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => $headers]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    }
    return $curl;
}
function parallel(array $requests): array
{
    global $base;
    $multi = curl_multi_init();
    $handles = [];
    foreach ($requests as [$path, $body, $token]) {
        $curl = handle('POST', $base . $path, $body, $token);
        $handles[] = $curl;
        curl_multi_add_handle($multi, $curl);
    }
    do {
        curl_multi_exec($multi, $running);
        if ($running) {
            curl_multi_select($multi, 1);
        }
    } while ($running);
    $results = [];
    foreach ($handles as $curl) {
        $results[] = [curl_getinfo($curl, CURLINFO_RESPONSE_CODE), json_decode(curl_multi_getcontent($curl), true, 32, JSON_THROW_ON_ERROR)];
        curl_multi_remove_handle($multi, $curl);
        curl_close($curl);
    }
    curl_multi_close($multi);
    return $results;
}
function captcha(string $scene): array
{
    // 仅测试进程直接写隔离 Redis 的已知答案；生产接口从不暴露答案。
    $id = bin2hex(random_bytes(24));
    (new RedisStore())->command('SET', 'jianyun:captcha:' . $id, json_encode(['hash' => hash('sha256', 'ABCDE'), 'scene' => $scene, 'ip' => '127.0.0.1']), 'EX', '300');
    return ['challenge_id' => $id, 'answer' => 'ABCDE'];
}
function scalar(string $sql, array $params = []): mixed
{
    global $db;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}
function order(int $product, bool $balance = false): array
{
    return ['product_id' => $product, 'hostname' => 'demo-host', 'password' => 'ExampleRootPass!23', 'billing_cycle' => 'monthly', 'config_options' => new stdClass(), 'use_balance' => $balance];
}

check(http('GET', '/health')[0] === 200, 'HTTP server and MySQL health');
$preflight = curl_init($base . '/api/v1/orders/checkout');
curl_setopt_array($preflight, [CURLOPT_CUSTOMREQUEST => 'OPTIONS', CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => ['Origin: https://console.example.test', 'Access-Control-Request-Method: POST']]);
$headers = curl_exec($preflight);
check(curl_getinfo($preflight, CURLINFO_RESPONSE_CODE) === 204 && stripos($headers, 'Access-Control-Allow-Origin: https://console.example.test') !== false, 'CORS preflight returns configured origin');
curl_close($preflight);
check((int)scalar('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()') === 26, 'schema has exactly 26 physical tables');
$r = http('GET', '/api/v1/captcha/config?scene=register');
$config = $r[1]['data'];
check($r[0] === 200 && $config['enabled'] && str_starts_with($config['img_base64'], 'data:image/png;base64,'), 'default local image captcha generation');
check((new RedisStore())->command('TTL', 'jianyun:captcha:' . $config['challenge_id']) > 0, 'captcha has Redis expiry');
$email = 'mvp-' . bin2hex(random_bytes(6)) . '@example.test';
$registration = ['email' => $email, 'password' => 'ExampleUserPass!23'];
check(http('POST', '/api/v1/auth/register', $registration)[0] === 422, 'captcha middleware rejects missing proof');
$db->prepare('INSERT INTO system_settings (`key`, value) VALUES (?, ?)')->execute(['captcha_scenes', json_encode(['login' => ['enabled' => false]])]);
try {
    check(http('GET', '/api/v1/captcha/config?scene=login')[1]['data']['enabled'] === false, 'captcha scene policy reads dynamic settings');
    check(http('GET', '/api/v1/captcha/config?scene=register')[1]['data']['enabled'] === true, 'captcha scene policy remains isolated');
} finally { $db->exec("DELETE FROM system_settings WHERE `key` = 'captcha_scenes'"); }
$proof = captcha('login');
check(http('POST', '/api/v1/auth/register', $registration + ['captcha_payload' => $proof])[0] === 422, 'captcha cannot cross scenes');
check((new RedisStore())->command('GET', 'jianyun:captcha:' . $proof['challenge_id']) === null, 'failed captcha is consumed atomically');
$proof = captcha('register');
$r = http('POST', '/api/v1/auth/register', $registration + ['captcha_payload' => $proof]);
check($r[0] === 200, 'register through HTTP');
$user = $r[1]['data']['user']; $uid = (int)$user['id']; $token = $r[1]['data']['token'];
check(!isset($user['password']) && $user['balance'] === '0.00', 'register returns safe account data with zero balance');
check(http('POST', '/api/v1/auth/register', $registration + ['captcha_payload' => $proof])[0] === 422, 'captcha cannot be replayed');
check(http('POST', '/api/v1/auth/register', $registration + ['captcha_payload' => captcha('register')])[0] === 409, 'duplicate email rejected');
check(http('POST', '/api/v1/auth/login', $registration + ['captcha_payload' => captcha('login')])[0] === 200, 'login through HTTP');
check(http('POST', '/api/v1/auth/login', ['email' => $email, 'password' => 'wrong', 'captcha_payload' => captcha('login')])[0] === 401, 'invalid password rejected');
check(http('GET', '/api/v1/auth/me', null, $token)[0] === 200, 'Bearer authentication');
check(http('GET', '/api/v1/auth/me', null, Security::generateToken(['uid' => $uid, 'sub_type' => 'admin']))[0] === 401, 'admin token cannot enter user routes');
check(http('GET', '/api/v1/auth/me', null, Security::generateToken(['uid' => $uid], -1))[0] === 401, 'expired token rejected');
check(http('GET', '/api/v1/auth/me', null, $token . 'x')[0] === 401, 'tampered token rejected');
$db->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([md5($registration['password']), $uid]);
check(http('POST', '/api/v1/auth/login', $registration + ['captcha_payload' => captcha('login')])[0] === 200 && password_verify($registration['password'], scalar('SELECT password FROM users WHERE id = ?', [$uid])), 'legacy MD5 login upgrades password hash');

$demo = new DemoService(); $product = $demo->seed();
check(http('GET', '/api/v1/products')[0] === 200, 'public product catalog');
$r = http('POST', "/api/v1/products/{$product}/calculate", ['billing_cycle' => 'monthly']);
check($r[0] === 200 && $r[1]['data']['total'] === '10.00', 'fixed-plan server price uses decimal strings');
check(http('POST', '/api/v1/orders/checkout', order($product))[0] === 401, 'checkout requires login');
check(http('POST', '/api/v1/orders/checkout', order($product) + ['coupon_code' => 'UNSUPPORTED'], $token)[0] === 400, 'unsupported coupon cannot be silently ignored');
$before = (int)scalar('SELECT COUNT(*) FROM instances');
check(http('POST', '/api/v1/orders/checkout', order($product, true), $token)[1]['code'] === 1001 && (int)scalar('SELECT COUNT(*) FROM instances') === $before, 'insufficient balance rolls back nested checkout entirely');
$r = http('POST', '/api/v1/orders/checkout', order($product), $token);
check($r[0] === 200 && $r[1]['data']['status'] === 'unpaid', 'checkout creates unpaid invoice');
$invoice = (int)$r[1]['data']['invoice_id']; $instance = (int)$r[1]['data']['instance_id'];
$encrypted = scalar('SELECT encrypted_password FROM instances WHERE id = ?', [$instance]);
check($encrypted !== order($product)['password'] && Security::decrypt($encrypted) === order($product)['password'], 'checkout pre-creates pending instance with AES-GCM password');
check((int)scalar('SELECT COUNT(*) FROM tasks WHERE invoice_id = ?', [$invoice]) === 0, 'unpaid checkout does not dispatch delivery');
$r = http('POST', "/api/v1/invoices/{$invoice}/pay", ['gateway' => 'balance'], $token);
check($r[0] === 409 && $r[1]['code'] === 1001, 'insufficient balance rejected');
check(scalar('SELECT status FROM invoices WHERE id = ?', [$invoice]) === 'unpaid' && (int)scalar('SELECT COUNT(*) FROM transactions WHERE invoice_id = ?', [$invoice]) === 0, 'insufficient balance rolls back CAS and ledger');

$ref = 'test_' . bin2hex(random_bytes(8)); $recharge = $demo->recharge($uid, '100.00', $ref);
check($demo->recharge($uid, '100.00', $ref) === $recharge && scalar('SELECT balance FROM users WHERE id = ?', [$uid]) === '100.00', 'demo recharge idempotent with ledger');
putenv('APP_ENV=production');
try { $demo->recharge($uid, '1.00', 'must_fail'); check(false, 'production demo guard'); }
catch (RuntimeException) { check(true, 'production cannot inject demo balance'); }
finally { putenv('APP_ENV=test'); }

$results = parallel(array_fill(0, 8, ["/api/v1/invoices/{$invoice}/pay", ['gateway' => 'balance'], $token]));
check(count(array_filter($results, static fn($r) => $r[0] === 200 && $r[1]['data']['status'] === 'paid')) === 8, '8 concurrent payments return paid idempotently');
check(scalar('SELECT balance FROM users WHERE id = ?', [$uid]) === '90.00', 'concurrent payment deducts wallet exactly once');
foreach (['transactions', 'payment_records', 'tasks'] as $table) {
    check((int)scalar("SELECT COUNT(*) FROM {$table} WHERE invoice_id = ?", [$invoice]) === 1, "concurrent payment writes one {$table} record");
}
check(scalar('SELECT status FROM instances WHERE id = ?', [$instance]) === 'pending' && scalar('SELECT status FROM tasks WHERE invoice_id = ?', [$invoice]) === 'pending', 'paid purchase awaits delivery without fake activation');
check(scalar('SELECT total_spent FROM users WHERE id = ?', [$uid]) === '10.00', 'total_spent increments exactly once');

$r = http('POST', '/api/v1/auth/register', ['email' => 'other-' . $email, 'password' => 'ExampleOtherPass!23', 'captcha_payload' => captcha('register')]);
$otherToken = $r[1]['data']['token'];
foreach (['GET' => '', 'POST' => '/pay'] as $method => $suffix) {
    check(http($method, "/api/v1/invoices/{$invoice}{$suffix}", $method === 'POST' ? ['gateway' => 'balance'] : null, $otherToken)[0] === 403, "{$method} invoice ownership enforced");
}

$r = http('POST', '/api/v1/orders/checkout', order($product), $token); $failedInvoice = (int)$r[1]['data']['invoice_id'];
$db->exec("CREATE TRIGGER test_tasks_fail BEFORE INSERT ON tasks FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected task failure'");
try {
    $r = http('POST', "/api/v1/invoices/{$failedInvoice}/pay", ['gateway' => 'balance'], $token);
    check($r[0] === 500 && $r[1]['msg'] === 'Internal server error', 'task failure exposes no SQL or secret');
    check(scalar('SELECT status FROM invoices WHERE id = ?', [$failedInvoice]) === 'unpaid' && scalar('SELECT balance FROM users WHERE id = ?', [$uid]) === '90.00', 'task failure rolls back paid state and wallet');
    check((int)scalar('SELECT COUNT(*) FROM transactions WHERE invoice_id = ?', [$failedInvoice]) === 0 && (int)scalar('SELECT COUNT(*) FROM payment_records WHERE invoice_id = ?', [$failedInvoice]) === 0, 'task failure rolls back ledger and payment record');
    $before = (int)scalar('SELECT COUNT(*) FROM instances');
    check(http('POST', '/api/v1/orders/checkout', order($product, true), $token)[0] === 500 && (int)scalar('SELECT COUNT(*) FROM instances') === $before, 'nested checkout-and-pay failure rolls back pre-created instance');
} finally { $db->exec('DROP TRIGGER test_tasks_fail'); }
$r = http('POST', '/api/v1/orders/checkout', order($product, true), $token);
check($r[0] === 200 && $r[1]['data']['status'] === 'paid', 'checkout with balance completes atomically');

$db->prepare('UPDATE products SET stock = 1 WHERE id = ?')->execute([$product]);
$results = parallel(array_fill(0, 4, ['/api/v1/orders/checkout', order($product), $token]));
$success = array_values(array_filter($results, static fn($r) => $r[0] === 200));
check(count($success) === 1 && scalar('SELECT stock FROM products WHERE id = ?', [$product]) === 0, 'concurrent checkout reserves final stock once');
$stockInvoice = (int)$success[0][1]['data']['invoice_id'];
check(http('POST', "/api/v1/invoices/{$stockInvoice}/cancel", [], $otherToken)[0] === 403, 'cancel enforces ownership');
$db->prepare('UPDATE invoices SET quote_expired_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = ?')->execute([$stockInvoice]);
check(http('POST', "/api/v1/invoices/{$stockInvoice}/pay", ['gateway' => 'balance'], $token)[0] === 409, 'expired quote cannot debit wallet');
$cancellations = parallel(array_fill(0, 4, ["/api/v1/invoices/{$stockInvoice}/cancel", [], $token]));
check(count(array_filter($cancellations, static fn($r) => $r[0] === 200 && $r[1]['data']['status'] === 'cancelled')) === 4 && scalar('SELECT stock FROM products WHERE id = ?', [$product]) === 1, 'concurrent cancel returns current state and releases stock once');
check(http('POST', "/api/v1/invoices/{$stockInvoice}/cancel", [], $token)[0] === 200 && scalar('SELECT stock FROM products WHERE id = ?', [$product]) === 1, 'cancel cannot release stock twice');
$r = http('POST', '/api/v1/orders/checkout', order($product), $token);
$expiredId = (int)$r[1]['data']['invoice_id'];
$db->prepare('UPDATE invoices SET quote_expired_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = ?')->execute([$expiredId]);
$process = proc_open([PHP_BINARY, dirname(__DIR__) . '/scripts/console.php', 'invoices:expire'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
foreach ($pipes as $pipe) { stream_get_contents($pipe); fclose($pipe); }
check(proc_close($process) === 0 && scalar('SELECT status FROM invoices WHERE id = ?', [$expiredId]) === 'cancelled' && scalar('SELECT stock FROM products WHERE id = ?', [$product]) === 1, 'expiry CLI cancels unpaid invoice and restores inventory');

try { $db->exec('UPDATE transactions SET remark = remark'); check(false, 'append-only ledger'); }
catch (PDOException) { check(true, 'database forbids ledger updates'); }
try {
    $db->prepare("INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, idempotency_key, remark) VALUES (?, 'recharge', '1.00', '0.00', '2.00', ?, 'invalid')")->execute([$uid, 'invalid_' . bin2hex(random_bytes(8))]);
    check(false, 'ledger balance constraint');
} catch (PDOException) { check(true, 'database rejects unbalanced ledger entry'); }
try { Money::positive(0.1); check(false, 'float rejection'); }
catch (ApiException) { check(true, 'money rejects float input'); }
Database::transaction(static function (PDO $db): void {
    try { Database::transaction(static function (): void { throw new RuntimeException('rollback savepoint'); }); }
    catch (RuntimeException) {}
    check($db->inTransaction(), 'inner transaction rollback preserves outer transaction');
});
check(!Database::getInstance()->inTransaction(), 'transaction depth restored after nested rollback');
$db->prepare("UPDATE users SET status = 'suspended' WHERE id = ?")->execute([$uid]);
check(http('GET', '/api/v1/auth/me', null, $token)[0] === 403, 'suspension immediately invalidates access');
echo "{$checks} checks passed.\n";
