<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

use App\Core\{ApiException, Database, Money, RedisStore, Security};
use App\Plugins\Captcha\{CaptchaDriverInterface, CaptchaDriverRegistry, CaptchaVerifyResult};
use App\Services\{CaptchaService, CatalogService, DemoService, InstanceActivationService, LedgerService};

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
function http(string $method, string $path, ?array $body = null, ?string $token = null, array $headers = []): array
{
    global $base;
    $curl = handle($method, $base . $path, $body, $token, $headers);
    $raw = curl_exec($curl);
    if ($raw === false) {
        throw new RuntimeException('HTTP transport failed');
    }
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    return [$status, json_decode($raw, true, 32, JSON_THROW_ON_ERROR)];
}
function handle(string $method, string $url, ?array $body, ?string $token, array $extraHeaders = [])
{
    $curl = curl_init($url);
    $headers = array_merge(['Content-Type: application/json'], $extraHeaders);
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
function captcha(string $scene, string $ip = '127.0.0.1'): array
{
    // 仅测试进程直接写隔离 Redis 的已知答案；生产接口从不暴露答案。
    $id = bin2hex(random_bytes(24));
    (new RedisStore())->command('SET', 'jianyun:captcha:' . $id, json_encode(['hash' => hash('sha256', 'ABCDE'), 'scene' => $scene, 'ip' => $ip]), 'EX', '300');
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
check((int)scalar('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()') === 27, 'schema has 26 business/support tables and one migration table');
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
$externalDriver = new class implements CaptchaDriverInterface {
    public function getDriverId(): string { return 'external_test'; }
    public function getName(): string { return 'Isolated test driver'; }
    public function getType(): string { return 'click'; }
    public function getConfigSchema(): array { return []; }
    public function generateChallenge(string $scene, string $clientIp): array { return ['challenge_id' => $scene . ':' . $clientIp]; }
    public function verify(array $payload, string $clientIp): CaptchaVerifyResult {
        return new CaptchaVerifyResult(($payload['scene'] ?? '') === 'register' && ($payload['proof'] ?? '') === $clientIp);
    }
};
$db->prepare('INSERT INTO system_settings (`key`, value) VALUES (?, ?)')->execute(['captcha_scenes', json_encode(['register' => ['enabled' => true, 'mode' => 'always', 'driver' => 'external_test']])]);
try {
    $service = new CaptchaService(new CaptchaDriverRegistry(['external_test' => static fn() => $externalDriver]));
    $externalConfig = $service->config('register', '192.0.2.8');
    check($externalConfig['driver'] === 'external_test' && $externalConfig['type'] === 'click'
        && $externalConfig['challenge_id'] === 'register:192.0.2.8', 'scene policy dispatches an injected driver without concrete core dependency');
    $service->verify('register', ['proof' => '192.0.2.8', 'scene' => 'login'], '192.0.2.8');
    check(true, 'captcha service supplies the trusted scene to the selected driver');
    try { $service->verify('register', ['proof' => 'wrong'], '192.0.2.8'); check(false, 'external captcha failure'); }
    catch (ApiException $e) { check($e->httpStatus === 422, 'selected driver rejection still blocks the business request'); }
    check(http('GET', '/api/v1/captcha/config?scene=register')[0] === 500, 'uninstalled configured driver fails closed through HTTP');
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
$forwarded = ['X-Forwarded-For: 203.0.113.7'];
$r = http('GET', '/api/v1/captcha/config?scene=login', null, null, $forwarded);
$challenge = json_decode((new RedisStore())->command('GET', 'jianyun:captcha:' . $r[1]['data']['challenge_id']), true);
check($challenge['ip'] === '203.0.113.7', 'captcha generation uses trusted client IP');
check(http('POST', '/api/v1/auth/login', $registration + ['captcha_payload' => captcha('login', '203.0.113.7')], null, $forwarded)[0] === 200
    && scalar('SELECT last_login_ip FROM users WHERE id = ?', [$uid]) === '203.0.113.7', 'captcha verification and login audit share trusted client IP');
check(http('POST', '/api/v1/auth/login', $registration + ['captcha_payload' => captcha('login', '203.0.113.7')])[0] === 422, 'captcha rejects different resolved client IP');
check(http('POST', '/api/v1/auth/login', ['email' => $email, 'password' => 'wrong', 'captcha_payload' => captcha('login')])[0] === 401, 'invalid password rejected');
check(http('GET', '/api/v1/auth/me', null, $token)[0] === 200, 'Bearer authentication');
check(http('GET', '/api/v1/auth/me', null, Security::generateToken(['uid' => $uid, 'sub_type' => 'admin']))[0] === 401, 'admin token cannot enter user routes');
check(http('GET', '/api/v1/auth/me', null, Security::generateToken(['uid' => $uid], -1))[0] === 401, 'expired token rejected');
check(http('GET', '/api/v1/auth/me', null, $token . 'x')[0] === 401, 'tampered token rejected');
$db->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([md5($registration['password']), $uid]);
check(http('POST', '/api/v1/auth/login', $registration + ['captcha_payload' => captcha('login')])[0] === 200 && password_verify($registration['password'], scalar('SELECT password FROM users WHERE id = ?', [$uid])), 'legacy MD5 login upgrades password hash');

$demo = new DemoService(); $product = $demo->seed();
$db->prepare("INSERT INTO products (provider_id, name, config_cache, markup_type) SELECT provider_id, 'same-provider-lock-test', config_cache, markup_type FROM products WHERE id = ?")->execute([$product]);
$sibling = (int)$db->lastInsertId();
$otherDb = new PDO('mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';dbname=' . getenv('DB_DATABASE') . ';charset=utf8mb4', getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$otherDb->exec('SET SESSION innodb_lock_wait_timeout = 1');
Database::transaction(static function (PDO $db) use ($otherDb, $product, $sibling): void {
    $catalog = new CatalogService();
    $catalog->quote($db, $product, 'monthly', [], true);
    $otherDb->beginTransaction();
    try {
        check($catalog->quote($otherDb, $sibling, 'monthly', [], true)['total'] === '10.00', 'different products on same provider do not block each other');
        try { $catalog->quote($otherDb, $product, 'monthly', [], true); check(false, 'product lock remains exclusive'); }
        catch (PDOException $e) { check(($e->errorInfo[1] ?? null) === 1205, 'same product still requires exclusive row lock'); }
    } finally { $otherDb->rollBack(); }
});
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
check((int)scalar('SELECT COUNT(*) FROM instances WHERE id = ? AND start_date IS NULL AND due_date IS NULL', [$instance]) === 1, 'unpaid checkout does not start rental period');
check((int)scalar('SELECT COUNT(*) FROM tasks WHERE invoice_id = ?', [$invoice]) === 0, 'unpaid checkout does not dispatch delivery');
$r = http('POST', "/api/v1/invoices/{$invoice}/pay", ['gateway' => 'balance'], $token);
check($r[0] === 409 && $r[1]['code'] === 1001, 'insufficient balance rejected');
check(scalar('SELECT status FROM invoices WHERE id = ?', [$invoice]) === 'unpaid' && (int)scalar('SELECT COUNT(*) FROM transactions WHERE invoice_id = ?', [$invoice]) === 0, 'insufficient balance rolls back CAS and ledger');

$ref = 'test_' . bin2hex(random_bytes(8)); $recharge = $demo->recharge($uid, '100.00', $ref);
check($demo->recharge($uid, '100.00', $ref) === $recharge && scalar('SELECT balance FROM users WHERE id = ?', [$uid]) === '100.00', 'demo recharge idempotent with ledger');
config(['app.env' => 'production']);
try { $demo->recharge($uid, '1.00', 'must_fail'); check(false, 'production demo guard'); }
catch (RuntimeException) { check(true, 'production cannot inject demo balance'); }
finally { config(['app.env' => 'test']); }

$results = parallel(array_fill(0, 8, ["/api/v1/invoices/{$invoice}/pay", ['gateway' => 'balance'], $token]));
check(count(array_filter($results, static fn($r) => $r[0] === 200 && $r[1]['data']['status'] === 'paid')) === 8, '8 concurrent payments return paid idempotently');
check(scalar('SELECT balance FROM users WHERE id = ?', [$uid]) === '90.00', 'concurrent payment deducts wallet exactly once');
foreach (['transactions', 'payment_records', 'tasks'] as $table) {
    check((int)scalar("SELECT COUNT(*) FROM {$table} WHERE invoice_id = ?", [$invoice]) === 1, "concurrent payment writes one {$table} record");
}
check(scalar('SELECT status FROM instances WHERE id = ?', [$instance]) === 'pending' && scalar('SELECT status FROM tasks WHERE invoice_id = ?', [$invoice]) === 'pending', 'paid purchase awaits delivery without fake activation');
check((int)scalar('SELECT COUNT(*) FROM instances WHERE id = ? AND start_date IS NULL AND due_date IS NULL', [$instance]) === 1, 'paid purchase does not consume rental period while delivery waits');
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
$activation = new InstanceActivationService();
foreach ([
    ['monthly', '2027-01-31 12:00:00', '2027-02-28 12:00:00'],
    ['monthly', '2028-01-31 12:00:00', '2028-02-29 12:00:00'],
    ['annually', '2028-02-29 12:00:00', '2029-02-28 12:00:00'],
] as [$cycle, $start, $due]) {
    $input = order($sibling, true); $input['billing_cycle'] = $cycle;
    // 年付演示价为100；只验证周期运算，先为该用户追加足够测试资金。
    $demo->recharge($uid, '100.00', 'activation_' . bin2hex(random_bytes(8)));
    $r = http('POST', '/api/v1/orders/checkout', $input, $token);
    check($r[0] === 200, "{$cycle} activation fixture paid");
    $activationInstance = (int)$r[1]['data']['instance_id'];
    $activationTask = (int)scalar('SELECT id FROM tasks WHERE instance_id = ?', [$activationInstance]);
    try {
        // 固定数据库会话时钟，直接测试实际激活方法的日期运算。
        $db->exec('SET timestamp = UNIX_TIMESTAMP(' . $db->quote($start) . ')');
        $db->prepare("UPDATE tasks SET status = 'running', worker_id = 'test-worker', execution_token = 9, lease_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE id = ?")->execute([$activationTask]);
        try { $activation->completeCreate($activationTask, 'test-worker', 8, 'remote-test', '192.0.2.1'); check(false, 'stale token'); }
        catch (ApiException $e) { check($e->httpStatus === 409, 'stale delivery token cannot activate instance'); }
        $db->prepare('UPDATE tasks SET lease_until = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE id = ?')->execute([$activationTask]);
        try { $activation->completeCreate($activationTask, 'test-worker', 9, 'remote-test', '192.0.2.1'); check(false, 'expired lease'); }
        catch (ApiException $e) { check($e->httpStatus === 409, 'expired delivery lease cannot activate instance'); }
        $db->prepare('UPDATE tasks SET lease_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE id = ?')->execute([$activationTask]);
        $db->exec("CREATE TRIGGER test_activation_fail BEFORE UPDATE ON instances FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected activation failure'");
        try {
            try { $activation->completeCreate($activationTask, 'test-worker', 9, 'remote-test', '192.0.2.1'); check(false, 'activation failure'); }
            catch (PDOException) {
                check(scalar('SELECT status FROM tasks WHERE id = ?', [$activationTask]) === 'running'
                    && (int)scalar('SELECT COUNT(*) FROM instances WHERE id = ? AND start_date IS NULL AND due_date IS NULL', [$activationInstance]) === 1, 'activation failure rolls back task success and rental dates');
            }
        } finally { $db->exec('DROP TRIGGER test_activation_fail'); }
        $activation->completeCreate($activationTask, 'test-worker', 9, 'remote-' . $activationTask, '192.0.2.1');
        check(scalar('SELECT start_date FROM instances WHERE id = ?', [$activationInstance]) === $start
            && scalar('SELECT due_date FROM instances WHERE id = ?', [$activationInstance]) === $due
            && scalar('SELECT status FROM instances WHERE id = ?', [$activationInstance]) === 'active'
            && scalar('SELECT status FROM tasks WHERE id = ?', [$activationTask]) === 'success', "delivery starts full {$cycle} term at {$start}, ends {$due}");
        try { $activation->completeCreate($activationTask, 'test-worker', 9, 'remote-' . $activationTask, '192.0.2.1'); check(false, 'duplicate activation'); }
        catch (ApiException) { check(scalar('SELECT due_date FROM instances WHERE id = ?', [$activationInstance]) === $due, 'duplicate delivery completion never extends term'); }
    } finally { $db->exec('SET timestamp = 0'); }
}
// 将隔离库重建为旧 MVP 日期约束，验证真实升级 SQL，而非仅检查文本。
$db->exec('UPDATE instances SET start_date = COALESCE(start_date, UTC_TIMESTAMP()), due_date = COALESCE(due_date, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 MONTH))');
$db->exec('ALTER TABLE instances MODIFY start_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, MODIFY due_date DATETIME NOT NULL');
$db->exec(file_get_contents(dirname(__DIR__) . '/database/migrations/001_delivery_dates.sql'));
check((int)scalar("SELECT COUNT(*) FROM instances WHERE status = 'pending' AND remote_instance_id IS NULL AND (start_date IS NOT NULL OR due_date IS NOT NULL)") === 0, 'migration clears old pending rental dates');
check(scalar('SELECT start_date FROM instances WHERE id = ?', [$activationInstance]) === $start && scalar('SELECT due_date FROM instances WHERE id = ?', [$activationInstance]) === $due, 'migration preserves delivered instance dates');
// 在一次性库还原旧索引，再运行实际迁移；已有台账必须保留。
$db->prepare("INSERT INTO instance_snapshots (instance_id, user_id, type, remote_snapshot_id, status) VALUES (?, ?, 'snapshot', 'legacy-42', 'active')")->execute([$activationInstance, $uid]);
$snapshotId = (int)$db->lastInsertId();
$db->exec('ALTER TABLE instance_snapshots DROP INDEX uk_instance_type_remote_snapshot, MODIFY remote_snapshot_id VARCHAR(100) NOT NULL, ADD UNIQUE KEY uk_remote_snapshot_id (remote_snapshot_id)');
$db->exec(file_get_contents(dirname(__DIR__) . '/database/migrations/002_snapshot_identity.sql'));
check(scalar('SELECT remote_snapshot_id FROM instance_snapshots WHERE id = ?', [$snapshotId]) === 'legacy-42', 'snapshot identity migration preserves existing remote IDs');
$insertSnapshot = $db->prepare("INSERT INTO instance_snapshots (instance_id, user_id, type, remote_snapshot_id, status) VALUES (?, ?, ?, ?, 'active')");
$insertSnapshot->execute([$instance, $uid, 'snapshot', 'legacy-42']);
check(true, 'different instances can own the same upstream snapshot ID');
$insertSnapshot->execute([$activationInstance, $uid, 'backup', 'legacy-42']);
check(true, 'snapshot and backup remote ID namespaces are isolated');
try { $insertSnapshot->execute([$activationInstance, $uid, 'snapshot', 'legacy-42']); check(false, 'duplicate snapshot identity'); }
catch (PDOException $e) { check(($e->errorInfo[1] ?? null) === 1062, 'duplicate snapshot identity within one instance and type is rejected'); }
$db->prepare("INSERT INTO instance_snapshots (instance_id, user_id) VALUES (?, ?), (?, ?)")->execute([$instance, $uid, $instance, $uid]);
check((int)scalar('SELECT COUNT(*) FROM instance_snapshots WHERE instance_id = ? AND remote_snapshot_id IS NULL', [$instance]) === 2, 'pending snapshots can reserve separate local rows before remote IDs exist');

// M0: 下单请求键与框架兼容回归，所有数据仍位于隔离库。
check(Database::getInstance() === Illuminate\Support\Facades\DB::connection()->getPdo(), 'domain and framework share one physical PDO');
Illuminate\Support\Facades\DB::transaction(function () use ($uid): void {
    try {
        Database::transaction(function (PDO $connection) use ($uid): void {
            $connection->prepare('UPDATE users SET last_login_ip = ? WHERE id = ?')->execute(['192.0.2.99', $uid]);
            throw new RuntimeException('savepoint');
        });
    } catch (RuntimeException) {}
    check(scalar('SELECT last_login_ip FROM users WHERE id = ?', [$uid]) !== '192.0.2.99', 'domain savepoint rolls back inside framework transaction');
});
$nulRegistration = ['email' => 'nul@example.test', 'password' => "ValidPrefix!\0Suffix", 'captcha_payload' => captcha('register')];
check(http('POST', '/api/v1/auth/register', $nulRegistration)[0] === 400, 'NUL password returns validation error without server failure');
check((int)scalar('SELECT COUNT(*) FROM users WHERE email = ?', ['nul@example.test']) === 0, 'invalid password never creates account');
$db->prepare('UPDATE products SET stock = -1 WHERE id = ?')->execute([$product]);
$demo->recharge($uid, '100.00', 'm0-checkout-fixture');
$keyed = order($product, true) + ['checkout_key' => 'm0-' . bin2hex(random_bytes(12))];
$balanceBefore = scalar('SELECT balance FROM users WHERE id = ?', [$uid]);
$results = parallel(array_fill(0, 8, ['/api/v1/orders/checkout', $keyed, $token]));
$ids = array_map(static fn($r) => $r[1]['data']['invoice_id'] ?? null, $results);
check(count(array_filter($results, static fn($r) => $r[0] === 200)) === 8 && count(array_unique($ids)) === 1, 'concurrent keyed checkout returns exactly one invoice');
$keyInvoice = (int)$ids[0];
check(scalar('SELECT balance FROM users WHERE id = ?', [$uid]) === bcsub($balanceBefore, '10.00', 2), 'keyed checkout deducts wallet once across retries');
foreach (['transactions', 'payment_records', 'tasks'] as $table) {
    check((int)scalar("SELECT COUNT(*) FROM {$table} WHERE invoice_id = ?", [$keyInvoice]) === 1, 'keyed checkout writes one '.$table);
}
$changed = $keyed; $changed['hostname'] = 'different-host';
check(http('POST', '/api/v1/orders/checkout', $changed, $token)[0] === 409, 'same checkout key rejects a changed payload');
$otherKeyOrder = $keyed; $otherKeyOrder['use_balance'] = false;
check(http('POST', '/api/v1/orders/checkout', $otherKeyOrder, $otherToken)[0] === 200, 'checkout keys are isolated between users');
$cancelOrder = order($product) + ['checkout_key' => 'cancel-' . bin2hex(random_bytes(12))];
$cancelId = http('POST', '/api/v1/orders/checkout', $cancelOrder, $token)[1]['data']['invoice_id'];
http('POST', "/api/v1/invoices/{$cancelId}/cancel", [], $token);
check(http('POST', '/api/v1/orders/checkout', $cancelOrder, $token)[1]['data']['status'] === 'cancelled', 'cancelled checkout key never creates another purchase');
$db->exec("CREATE TRIGGER m0_fail_task BEFORE INSERT ON tasks FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced'");
$retryOrder = order($product, true) + ['checkout_key' => 'rollback-' . bin2hex(random_bytes(12))];
try { check(http('POST', '/api/v1/orders/checkout', $retryOrder, $token)[0] === 500, 'keyed checkout failure rolls back'); }
finally { $db->exec('DROP TRIGGER m0_fail_task'); }
check(http('POST', '/api/v1/orders/checkout', $retryOrder, $token)[0] === 200, 'rolled-back checkout key can retry successfully');
$db->prepare('UPDATE products SET stock = 10 WHERE id = ?')->execute([$product]);
$reservations = parallel(array_fill(0, 6, ['/api/v1/orders/checkout', order($product), $token]));
$reserved = array_values(array_filter($reservations, static fn($r) => $r[0] === 200));
check(count($reserved) === 3 && scalar('SELECT stock FROM products WHERE id = ?', [$product]) === 7, 'concurrent unpaid reservations respect per-user finite-stock cap');
check(http('POST', '/api/v1/orders/checkout', order($product, true), $token)[0] === 200, 'paid checkout is not blocked by unpaid reservation cap');
$released = $reserved[0][1]['data']['invoice_id'];
http('POST', "/api/v1/invoices/{$released}/cancel", [], $token);
check(http('POST', '/api/v1/orders/checkout', order($product), $token)[0] === 200, 'cancellation frees a reservation slot');
check(http('GET', '/api/v1/auth/me')[0] === 401, 'authenticated request cannot leak identity to next anonymous request');
check(http('GET', '/api/v1/invoices/999999', null, $token)[0] === 404, 'missing invoice preserves 404 response');
$method = http('PUT', '/api/v1/products');
check($method[0] === 405 && $method[1]['data'] === null, 'framework method rejection preserves API envelope');

$db->prepare("UPDATE users SET status = 'suspended' WHERE id = ?")->execute([$uid]);
check(http('GET', '/api/v1/auth/me', null, $token)[0] === 403, 'suspension immediately invalidates access');

// 模拟上一版原生 MVP 的原地升级，不使用部署库，也不删除业务数据。
$preservedBalance = scalar('SELECT balance FROM users WHERE id = ?', [$uid]);
$preservedTasks = scalar('SELECT COUNT(*) FROM tasks');
$db->exec('ALTER TABLE invoices DROP INDEX uk_user_checkout, DROP COLUMN checkout_key, DROP COLUMN checkout_hash');
$db->exec('ALTER TABLE notification_deliveries DROP COLUMN admin_id, DROP COLUMN context, MODIFY user_id BIGINT UNSIGNED NOT NULL');
$db->exec('ALTER TABLE users DROP COLUMN auth_version');
$db->exec('DROP TABLE migrations');
$process = proc_open([PHP_BINARY, dirname(__DIR__).'/artisan', 'migrate', '--force'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
foreach ($pipes as $pipe) { stream_get_contents($pipe); fclose($pipe); }
check(proc_close($process) === 0, 'standard Laravel migrator upgrades the reviewed native MVP');
check(scalar('SELECT balance FROM users WHERE id = ?', [$uid]) === $preservedBalance && scalar('SELECT COUNT(*) FROM tasks') === $preservedTasks, 'schema upgrade preserves wallet and pending task facts');
check((int)scalar("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'invoices' AND column_name IN ('checkout_key','checkout_hash')") === 2, 'upgrade installs checkout idempotency columns');
check((int)scalar('SELECT COUNT(*) FROM migrations') === 6, 'framework records exactly six reviewed schema versions');
check((int)scalar("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='notification_deliveries' AND column_name IN ('admin_id','context')")===2,
    'upgrade adds typed notification recipients and event context without a new table');
check((int)scalar("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='auth_version'")===1,
    'upgrade adds customer credential revocation version without invalidating version-zero sessions');

echo "{$checks} checks passed.\n";
