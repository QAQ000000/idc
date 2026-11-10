<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
set_exception_handler(static function (Throwable $e): never {
    fwrite(STDERR, $e->getMessage()."\n".$e->getTraceAsString()."\n");
    exit(1);
});
use App\Core\{Database,Security,RedisStore};
use App\Services\{AuthService,BillingService,PasswordService,NotificationService,NotificationSettingsService,DemoService,InstanceConsoleService};
use Illuminate\Support\Facades\RateLimiter;

if (config('app.env') !== 'test' || config('database.connections.mysql.database') !== 'jianyun_test') {
    throw new RuntimeException('Isolated database required');
}
$db = Database::getInstance();
$checks = 0;
$auth = new AuthService();
$billing = new BillingService();
$passwords = new PasswordService();
function selfCheck(bool $v, string $m): void
{
    global $checks;
    if (! $v) {
        throw new RuntimeException('FAIL customer: '.$m);
    }$checks++;
    echo "PASS customer: $m\n";
}
function selfReject(callable $f, string $m): void
{
    try {
        $f();
    } catch (Throwable) {
        selfCheck(true, $m);

        return;
    }throw new RuntimeException('Expected rejection: '.$m);
}
function selfHandle(string $method, string $path, ?array $body = null, ?string $token = null): CurlHandle
{
    $c = curl_init(getenv('TEST_BASE_URL').'/api/v1'.$path);
    curl_setopt_array($c, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => array_values(array_filter(['Content-Type: application/json', $token ? 'Authorization: Bearer '.$token : null]))]);
    if ($body !== null) {
        curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    }

    return $c;
}
function selfHttp(string $method, string $path, ?array $body = null, ?string $token = null): array
{
    $c = selfHandle($method, $path, $body, $token);
    $raw = curl_exec($c);
    $status = curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);

    return [$status, json_decode($raw, true, 32, JSON_THROW_ON_ERROR)];
}
function selfCaptcha(string $scene = 'password_reset'): array
{
    $id = bin2hex(random_bytes(24));
    (new RedisStore())->command('SET', 'jianyun:captcha:'.$id, json_encode(['hash' => hash('sha256', 'ABCDE'), 'scene' => $scene, 'ip' => '127.0.0.1']), 'EX', '300');

    return ['challenge_id' => $id, 'answer' => 'ABCDE'];
}
function selfMailCooldown(string $email): void
{
    (new RedisStore())->command('DEL', 'password-forgot-email:'.Security::requestFingerprint($email).':cooldown');
}
$session = $auth->register(['email' => 'self-service@example.test', 'password' => 'CustomerOriginal12!']);
$user = (int) $session['user']['id'];
$token = $session['token'];
$other = $auth->register(['email' => 'self-other@example.test', 'password' => 'OtherCustomer12!']);
$admin = Security::generateToken(['uid' => 1, 'sub_type' => 'admin']);
$demo = new DemoService();
$product = $demo->seed();
$demo->recharge($user, '100.00', 'self-service-fixture');
$order = ['product_id' => $product, 'hostname' => 'self-service', 'password' => 'FixtureRootPass12', 'billing_cycle' => 'monthly', 'checkout_key' => 'customer-self-service-checkout'];
$invoice = $billing->checkout($user, $order);
$invoiceId = (int) $invoice['invoice_id'];
for ($n = 0; $n < 25; $n++) {
    $db->prepare("INSERT INTO invoices(user_id,type,total,status) VALUES(?,'recharge','1.00','paid')")->execute([$user]);
}
selfCheck(selfHttp('GET', '/invoices')[0] === 401, 'invoice listing requires client login');
selfCheck(selfHttp('GET', '/invoices', null, $admin)[0] === 401, 'admin token cannot list a customer wallet');
$page = selfHttp('GET', '/invoices?page=1&per_page=20', null, $token)[1]['data'];
selfCheck(count($page['items']) === 20 && $page['total'] === 27, 'default page finds all own historical and unpaid invoices');
$page2 = selfHttp('GET', '/invoices?page=2&per_page=20', null, $token)[1]['data'];
selfCheck(count($page2['items']) === 7 && $page2['items'][5]['invoice_id'] === $invoiceId, 'second page includes the original unpaid order');
selfCheck(count(array_intersect(array_column($page['items'], 'invoice_id'), array_column($page2['items'], 'invoice_id'))) === 0, 'pagination has no duplicate rows');
$unpaid = selfHttp('GET', '/invoices?status=unpaid', null, $token)[1]['data'];
selfCheck($unpaid['total'] === 1 && (int) $unpaid['items'][0]['invoice_id'] === $invoiceId, 'unpaid filter resumes the existing invoice ID');
selfCheck(selfHttp('GET', '/invoices?status=paid', null, $token)[1]['data']['total'] === 26, 'paid filter finds historical records');
selfCheck(selfHttp('GET', '/invoices?status=cancelled', null, $token)[1]['data']['items'] === [], 'empty status filter returns an empty page');
selfCheck(selfHttp('GET', '/invoices?user_id='.$user, null, $other['token'])[1]['data']['total'] === 0, 'caller cannot switch owner by query string');
selfCheck(! str_contains(json_encode($page2), 'price_snapshot') && ! str_contains(json_encode($page2), 'checkout_hash'), 'list does not expose configuration secrets or checkout digest');
foreach (['page=0', 'page[]=1', 'per_page=101', 'status=invalid', 'status[]=paid'] as $query) {
    selfCheck(selfHttp('GET', '/invoices?'.$query, null, $token)[0] === 400, 'invalid filter rejected: '.$query);
}
$before = $db->query('SELECT COUNT(*) FROM invoices')->fetchColumn();
selfCheck($billing->checkout($user, $order)['invoice_id'] === $invoiceId && $db->query('SELECT COUNT(*) FROM invoices')->fetchColumn() === $before, 'original checkout replay still refers to original order');
$billing->pay($user, $invoiceId);
$billing->invoice($user, $invoiceId);
$s = $db->prepare('SELECT COUNT(*) FROM tasks WHERE invoice_id=?');
$s->execute([$invoiceId]);
selfCheck((int) $s->fetchColumn() === 1, 'resuming the original invoice settles and enqueues once');
$expired = $billing->checkout($user, array_replace($order, ['checkout_key' => 'customer-expired-checkout']));
$db->prepare('UPDATE invoices SET quote_expired_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id=?')->execute([$expired['invoice_id']]);
selfCheck((int) $billing->invoice($user, (int) $expired['invoice_id'])['quote_expired'] === 1, 'detail reports expired quote without changing invoice state');
selfReject(fn () => $billing->pay($user, (int) $expired['invoice_id']), 'expired quote remains rejected by final payment gate');
(new App\Services\OutboxWorker())->runOnce('customer-fixture');
$s = $db->prepare("SELECT status FROM tasks WHERE invoice_id=?");
$s->execute([$invoiceId]);
selfCheck($s->fetchColumn() === 'failed', 'manual delivery fixture is durably visible for operator review');
$baseline = [];
foreach (['instances', 'invoices', 'transactions', 'payment_records', 'tasks'] as $table) {
    $baseline[$table] = $db->query("SELECT * FROM $table ORDER BY id")->fetchAll();
}
$wallet = $db->query('SELECT id,balance,total_spent FROM users ORDER BY id')->fetchAll();
$settings = ['enabled' => true, 'task_alerts' => false, 'due_reminders' => false, 'site_url' => 'https://shop.example.test', 'admin_ids' => [1],
    'config' => ['host' => '127.0.0.1', 'port' => (int) getenv('SMTP_TEST_PORT'), 'security' => 'test_plain', 'username' => '', 'password' => '', 'from' => 'shop@example.test', 'from_name' => '客户自助隔离验收']];
(new NotificationSettingsService())->configure(1, $settings);
$db->prepare("INSERT INTO system_settings(`key`,value) VALUES('captcha_scenes',?) ON DUPLICATE KEY UPDATE value=VALUES(value)")->execute([json_encode(['password_reset' => ['enabled' => true, 'driver' => 'builtin_image']])]);
selfCheck(selfHttp('POST', '/auth/password/forgot', ['email' => 'self-service@example.test'])[0] === 422, 'password recovery requires its captcha scene');
selfCheck(selfHttp('POST', '/auth/password/forgot', ['email' => 'self-service@example.test', 'captcha_payload' => selfCaptcha('login')])[0] === 422, 'login captcha does not authorize password recovery');
$request = ['email' => 'self-service@example.test', 'captcha_payload' => selfCaptcha()];
$r = selfHttp('POST', '/auth/password/forgot', $request);
selfCheck($r[0] === 200 && $r[1]['data'] === ['accepted' => true], 'forgot endpoint gives only a generic accepted response');
$s = $db->prepare("SELECT * FROM notification_deliveries WHERE user_id=? AND JSON_UNQUOTE(JSON_EXTRACT(context,'$.kind'))='password_reset' ORDER BY id DESC LIMIT 1");
$s->execute([$user]);
$delivery = $s->fetch();
$context = json_decode($delivery['context'], true);
$resetToken = Security::decrypt($context['encrypted_token']);
$claims = PasswordService::resetClaims($resetToken);
selfCheck($claims && $claims['av'] === 0 && $claims['exp'] - $claims['iat'] === 900, 'reset token is purpose-scoped and expires in fifteen minutes');
selfCheck(! str_contains(json_encode($delivery), $resetToken) && ! str_contains(json_encode($claims), 'self-service@example.test'), 'queued token encrypted; claims do not contain plaintext email');
$missing = selfHttp('POST', '/auth/password/forgot', ['email' => 'missing-self@example.test', 'captcha_payload' => selfCaptcha()]);
selfCheck($missing === $r, 'missing and registered accounts have identical status and public response');
$repeat = selfHttp('POST', '/auth/password/forgot', ['email' => 'self-service@example.test', 'captcha_payload' => selfCaptcha()]);
$s->execute([$user]);
selfCheck($repeat === $r && (int) $s->fetch()['id'] === (int) $delivery['id'], 'email cooldown prevents a second mail without revealing account existence');
selfCheck(selfHttp('GET', '/auth/me', null, $resetToken)[0] === 401, 'reset credential cannot act as a client login');
selfCheck(selfHttp('GET', '/invoices', null, $resetToken)[0] === 401, 'reset credential cannot inspect financial records');
selfCheck(selfHttp('POST', '/auth/password/reset', ['token' => $token, 'new_password' => 'CustomerReplacement12!'])[0] === 400, 'normal login JWT cannot reset a password');
$notifications = new NotificationService();
$notifications->dispatch();
$s = $db->prepare('SELECT status,context FROM notification_deliveries WHERE id=?');
$s->execute([$delivery['id']]);
$sent = $s->fetch();
selfCheck($sent['status'] === 'success' && ! str_contains($sent['context'], 'encrypted_token'), 'mail sender accepts reset message and removes its credential ciphertext');
$smtp = json_decode(file_get_contents(getenv('SMTP_TEST_CONTROL').'/state'), true);
$text = quoted_printable_decode(end($smtp['messages'])['body']);
selfCheck(str_contains($text, '/reset-password#token=') && str_contains($text, $resetToken), 'SMTP message contains site fragment reset link');
selfCheck(! str_contains(json_encode($notifications->inspect()), $resetToken), 'operational delivery inspection never shows reset credential');
foreach (['short', str_repeat('a', 73), "PasswordNul\0Test"] as $bad) {
    selfCheck(selfHttp('POST', '/auth/password/reset', ['token' => $resetToken, 'new_password' => $bad])[0] === 400, 'invalid password leaves reset credential usable');
}
$multi = curl_multi_init();
$handles = [];
for ($n = 0; $n < 2; $n++) {
    $c = selfHandle('POST', '/auth/password/reset', ['token' => $resetToken, 'new_password' => 'CustomerReplacement12!']);
    curl_multi_add_handle($multi, $c);
    $handles[] = $c;
}
do {
    curl_multi_exec($multi, $running);
    if ($running) {
        curl_multi_select($multi, 1);
    }
} while ($running);
$codes = [];
foreach ($handles as $c) {
    $codes[] = curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_multi_remove_handle($multi, $c);
    curl_close($c);
}curl_multi_close($multi);
sort($codes);
selfCheck($codes === [200, 400], 'concurrent reset consumes one credential version exactly once');
selfCheck(selfHttp('POST', '/auth/password/reset', ['token' => $resetToken, 'new_password' => 'ReplayPassword12!'])[0] === 400, 'used reset credential cannot be replayed');
selfCheck(selfHttp('GET', '/auth/me', null, $token)[0] === 401, 'password reset immediately rejects old customer login');
selfCheck(selfHttp('GET', '/invoices', null, Security::generateToken(['uid' => $user]))[0] === 401, 'legacy token without auth version is rejected after first reset');
selfReject(fn () => (new InstanceConsoleService())->principal($token), 'VNC continuity principal rejects the revoked old session');
selfReject(fn () => $auth->login(['email' => 'self-service@example.test', 'password' => 'CustomerOriginal12!']), 'old account password no longer authenticates');
$new = $auth->login(['email' => 'self-service@example.test', 'password' => 'CustomerReplacement12!']);
selfCheck(Security::verifyToken($new['token'])['av'] === 1 && selfHttp('GET', '/auth/me', null, $new['token'])[0] === 200, 'new login uses current version and remains functional');
selfCheck(! array_key_exists('auth_version', selfHttp('GET', '/auth/me', null, $new['token'])[1]['data']), 'me response excludes internal session version');
$stale = Security::generateToken(['uid' => $user, 'sub_type' => 'password_reset', 'purpose' => 'change_account_password', 'av' => 1, 'email_hash' => Security::requestFingerprint('self-service@example.test'), 'jti' => 'expired'], -1);
selfCheck(selfHttp('POST', '/auth/password/reset', ['token' => $stale, 'new_password' => 'ExpiredPassword12!'])[0] === 400, 'expired reset token rejected');
$impersonated = Security::generateToken(['uid' => $user, 'av' => 1, 'is_impersonated' => true, 'operator_admin_id' => 1]);
selfCheck(selfHttp('POST', '/auth/password', ['current_password' => 'CustomerReplacement12!', 'new_password' => 'ForbiddenChange12!'], $impersonated)[0] === 403, 'impersonation cannot change customer password');
selfCheck(selfHttp('POST', '/auth/password', ['current_password' => 'WrongPassword12!', 'new_password' => 'CustomerChangedAgain12!'], $new['token'])[0] === 400, 'authenticated change requires current password');
selfCheck(selfHttp('POST', '/auth/password', ['current_password' => 'CustomerReplacement12!', 'new_password' => 'CustomerChangedAgain12!'], $admin)[0] === 401, 'administrator token is not customer password authority');
selfCheck(selfHttp('POST', '/auth/password', ['current_password' => 'CustomerReplacement12!', 'new_password' => 'CustomerChangedAgain12!'], $new['token'])[0] === 200, 'owner can change current account password');
selfCheck(selfHttp('GET', '/auth/me', null, $new['token'])[0] === 401, 'authenticated change revokes its own old login');
$fresh = $auth->login(['email' => 'self-service@example.test', 'password' => 'CustomerChangedAgain12!']);
selfCheck(Security::verifyToken($fresh['token'])['av'] === 2, 'second password change advances credential version once');
// A queued token from a superseded version must never be sent.
selfMailCooldown('self-service@example.test');
$passwords->request(['email' => 'self-service@example.test']);
$s = $db->prepare("SELECT id FROM notification_deliveries WHERE user_id=? AND status='pending' ORDER BY id DESC LIMIT 1");
$s->execute([$user]);
$staleId = (int) $s->fetchColumn();
$passwords->change($user, 2, ['current_password' => 'CustomerChangedAgain12!', 'new_password' => 'CustomerFinalPassword12!']);
selfCheck($notifications->claim($staleId, (new NotificationSettingsService())->load()) === null, 'credential change suppresses an older queued reset mail');
$s = $db->prepare('SELECT context FROM notification_deliveries WHERE id=?');
$s->execute([$staleId]);
selfCheck(! str_contains($s->fetchColumn(), 'encrypted_token'), 'suppressed reset message discards credential ciphertext');
$db->prepare("UPDATE users SET status='suspended' WHERE id=?")->execute([$user]);
selfMailCooldown('self-service@example.test');
$beforeCount = $db->query('SELECT COUNT(*) FROM notification_deliveries')->fetchColumn();
$passwords->request(['email' => 'self-service@example.test']);
selfCheck($db->query('SELECT COUNT(*) FROM notification_deliveries')->fetchColumn() === $beforeCount, 'disabled account does not receive a reset capability');
$db->prepare("UPDATE users SET status='active' WHERE id=?")->execute([$user]);
RateLimiter::clear('password-forgot-ip:'.Security::requestFingerprint(Security::getClientIp()));
for ($n = 0; $n < 10; $n++) {
    $passwords->request(['email' => 'unknown-rate-'.$n.'@example.test']);
}
selfReject(fn () => $passwords->request(['email' => 'unknown-rate-limit@example.test']), 'forgot request IP rate limit enforced for unknown accounts too');
foreach ($baseline as $table => $rows) {
    selfCheck($db->query("SELECT * FROM $table ORDER BY id")->fetchAll() === $rows, 'account self-service does not alter '.$table);
}
selfCheck($db->query('SELECT id,balance,total_spent FROM users ORDER BY id')->fetchAll() === $wallet, 'password lifecycle leaves all wallet balances and spending intact');
$settings['enabled'] = false;
(new NotificationSettingsService())->configure(1, $settings);
echo "Customer self-service checks: $checks\n";
