<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use App\Core\{ApiException, Database, Security};
use App\Services\{AdminAuthService, AuthService, BillingService, DemoService, PaymentService, PaymentSettingsService, WalletService};

if (config('app.env') !== 'test' || config('database.connections.mysql.database') !== 'jianyun_test') {
    throw new RuntimeException('Isolated DB required');
}
set_exception_handler(static function (Throwable $e): never {
    fwrite(STDERR, (string) $e.PHP_EOL);
    exit(1);
});

// Subprocesses let the parent hold a real row lock and prove both admission orderings.
if (($argv[1] ?? '') === 'child') {
    $v = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
    echo "READY\n";
    flush();
    try {
        $result = match ($v['op']) {
            'checkout' => (new PaymentService())->checkout($v['user'], $v['invoice'], 'epay'),
            'save' => (new PaymentSettingsService())->save($v['admin'], $v['body']),
        };
        echo json_encode(['status' => 200, 'data' => $result], JSON_THROW_ON_ERROR);
    } catch (ApiException $e) {
        echo json_encode(['status' => $e->httpStatus], JSON_THROW_ON_ERROR);
    }
    exit;
}
$db = Database::getInstance();
$checks = 0;
$root = '/admin/payment-settings/epay';
function psCheck(bool $ok, string $why): void
{
    global $checks;
    if (! $ok) {
        throw new RuntimeException('FAIL: '.$why);
    }++$checks;
    echo 'PASS: '.$why.PHP_EOL;
}
function psVal(string $sql, array $params = []): mixed
{
    global $db;
    $s = $db->prepare($sql);
    $s->execute($params);

    return $s->fetchColumn();
}
function psRow(): ?array
{
    global $db;

    return PaymentSettingsService::row($db, 'epay');
}
function psHttp(string $path, ?string $token = null, string $method = 'GET', ?array $body = null): array
{
    $notify = str_contains($path, '/payments/epay/notify');
    $cache = '';
    $h = curl_init(getenv('TEST_BASE_URL').'/api/v1'.$path);
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 25, CURLOPT_HTTPHEADER => array_filter(['Content-Type: '.($notify ? 'application/x-www-form-urlencoded' : 'application/json'), $token ? 'Authorization: Bearer '.$token : null]), CURLOPT_HEADERFUNCTION => static function ($h, $line) use (&$cache) {
        if (str_starts_with(strtolower($line), 'cache-control:')) {
            $cache = trim(substr($line, 14));
        }

        return strlen($line);
    }]);
    if ($body !== null) {
        curl_setopt($h, CURLOPT_POSTFIELDS, $notify ? http_build_query($body) : json_encode($body, JSON_THROW_ON_ERROR));
    }
    $raw = curl_exec($h);
    $code = curl_getinfo($h, CURLINFO_RESPONSE_CODE);
    curl_close($h);

    return [$code, $notify ? $raw : json_decode($raw, true, 32, JSON_THROW_ON_ERROR), $cache];
}
function psGet(): array
{
    global $root,$token;

    return psHttp($root, $token)[1]['data'];
}
function psBody(?array $state = null): array
{
    $state ??= psGet();

    return array_intersect_key($state, array_flip(['enabled', 'endpoint', 'pid', 'type', 'notify_url', 'return_url'])) + ['expected_version' => $state['version'], 'key_action' => 'keep'];
}
function psSave(array $body): array
{
    global $root,$token;

    return psHttp($root, $token, 'PATCH', $body);
}
function psInvoice(string $key): int
{
    global $uid;

    return (int) (new WalletService())->recharge($uid, ['amount' => '1.00', 'request_key' => 'payment-settings-'.$key])['invoice_id'];
}
function psForm(int $invoice): array
{
    global $uid;

    return (new PaymentService())->checkout($uid, $invoice, 'epay')['checkout'];
}
function psNotice(array $form, string $trade): array
{
    $n = ['pid' => $form['fields']['pid'], 'out_trade_no' => $form['fields']['out_trade_no'], 'money' => $form['fields']['money'], 'trade_no' => $trade, 'trade_status' => 'TRADE_SUCCESS', 'sign_type' => 'MD5'];
    $n['sign'] = \Plugins\payments\epay\EpayDriver::sign($n, 'PS_FINAL_PRIVATE_KEY');

    return $n;
}
function psSpawn(array $input): array
{
    $p = proc_open([PHP_BINARY, __FILE__, 'child'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    if (trim(fgets($pipes[1])) !== 'READY') {
        throw new RuntimeException('child not ready');
    }

    return [$p, $pipes];
}
function psFinish(array $child): array
{
    [$p,$pipes] = $child;
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($p) !== 0) {
        throw new RuntimeException($err.$out);
    }

    return json_decode($out, true, 32, JSON_THROW_ON_ERROR);
}
function psWaiting(array $child): void
{
    global $db;
    $until = microtime(true) + 8;
    do {
        if ((int) $db->query('SELECT COUNT(*) FROM performance_schema.data_lock_waits')->fetchColumn() > 0) {
            return;
        }usleep(10000);
    } while (microtime(true) < $until);
    throw new RuntimeException('No real lock wait observed');
}

require_once __DIR__.'/reset_payment_fixture.php';
resetPaymentFixture();
$tokens = [];
$ids = [];
foreach (['super_admin', 'finance', 'support', 'operator'] as $role) {
    $ids[$role] = (new AdminAuthService())->create(['username' => 'payment-settings-'.$role, 'email' => 'payment-settings-'.$role.'@example.test', 'password' => 'PaymentSettings!2026', 'role' => $role]);
    $tokens[$role] = Security::generateToken(['uid' => $ids[$role], 'sub_type' => 'admin']);
}
$token = $tokens['super_admin'];
$admin = $ids['super_admin'];
$customer = (new AuthService())->register(['email' => 'payment-settings-customer@example.test', 'password' => 'PaymentSettings!2026']);
$uid = (int) $customer['user']['id'];
foreach ([null, $customer['token']] as $t) {
    foreach (['GET', 'PATCH'] as $method) {
        psCheck(psHttp($root, $t, $method, $method === 'PATCH' ? ['enabled' => false] : null)[0] === 401, 'anonymous/customer denied '.$method);
    }
}
foreach (['finance', 'support', 'operator'] as $role) {
    foreach (['GET', 'PATCH'] as $method) {
        psCheck(psHttp($root, $tokens[$role], $method, $method === 'PATCH' ? ['enabled' => false] : null)[0] === 403, 'non-super denied '.$role.' '.$method);
    }
}
$state = psGet();
psCheck(! $state['enabled'] && ! $state['configured'] && ! $state['identity_locked'] && ! $state['key_configured'], 'empty config is disabled and unconfigured');
psCheck(psRow() === null, 'GET does not create settings row');
$body = ['expected_version' => $state['version'], 'enabled' => false, 'endpoint' => 'https://pay.example.test', 'pid' => 'ps-fixture', 'type' => 'alipay', 'notify_url' => 'https://shop.example.test/api/v1/payments/epay/notify', 'return_url' => 'https://shop.example.test', 'key_action' => 'replace', 'key' => 'PS_INITIAL_PRIVATE_KEY'];
psCheck(psSave($body)[0] === 200 && str_contains(psHttp($root, $token)[2], 'no-store'), 'first disabled configuration saved with no-store');
$row = psRow();
psCheck(! str_contains($row['encrypted_credentials'], 'PS_INITIAL') && json_decode(Security::decrypt($row['encrypted_credentials']), true)['key'] === 'PS_INITIAL_PRIVATE_KEY', 'key AES encrypted in original settings row');
psCheck(psSave($body)[0] === 409, 'replaying original initial save cannot overwrite');
$public = json_encode(psHttp($root, $token));
psCheck(! str_contains($public, 'PS_INITIAL') && ! str_contains($public, $row['encrypted_credentials']) && psGet()['key_configured'], 'no plaintext or ciphertext in GET');
$body = psBody();
$before = psRow();
$body['pid'] = '1000';
$body['endpoint'] = 'https://pay-new.example.test';
$body['key_action'] = 'replace';
$body['key'] = 'PS_FINAL_PRIVATE_KEY';
psCheck(psSave($body)[0] === 200, 'identity editable before first attempt');
$legacy = json_decode(psRow()['value'], true);
$legacy['pid'] = 1000;
$db->prepare("UPDATE system_settings SET value=? WHERE `key`='payment.epay'")->execute([json_encode($legacy)]);
psCheck(psGet()['pid'] === '1000', 'legacy numeric merchant ID is projected as a string');
psCheck(psSave(psBody())[0] === 200, 'legacy numeric merchant can be saved without changing identity');
$body = psBody();
foreach ([['enabled' => 1], ['key_action' => 'keep', 'key' => 'injected'], ['key_action' => 'replace', 'key' => ''], ['key_action' => 'replace', 'key' => ['bad']], ['pid' => ['bad']], ['pid' => 'bad&pid'], ['endpoint' => 'https://user:pass@bad.test'], ['notify_url' => 'https://shop.example.test/callback?key=leak'], ['return_url' => 'https://shop.example.test/path'], ['driver' => 'other'], ['expected_version' => str_repeat('0', 64)]] as $change) {
    psCheck(in_array(psSave(array_replace($body, $change))[0], [400, 409], true), 'invalid input rejected without leaking configuration');
}
$invoice = psInvoice('disabled');
psCheck(psHttp('/invoices/'.$invoice.'/pay', $customer['token'], 'POST', ['gateway' => 'epay'])[0] === 409, 'disabled new cashier refused');
psCheck((int) psVal('SELECT COUNT(*) FROM payment_records WHERE invoice_id=?', [$invoice]) === 0, 'disabled cashier creates no attempt');
$body = psBody();
$body['enabled'] = true;
$cipher = psRow()['encrypted_credentials'];
psCheck(psSave($body)[0] === 200 && psRow()['encrypted_credentials'] === $cipher, 'enable with keep preserves exact ciphertext');
$validRow = psRow();
$broken = json_decode($validRow['value'], true);
$broken['type'] = 'invalid-legacy-type';
$db->prepare("UPDATE system_settings SET value=? WHERE `key`='payment.epay'")->execute([json_encode($broken)]);
psCheck(psHttp('/invoices/'.$invoice.'/pay', $customer['token'], 'POST', ['gateway' => 'epay'])[0] === 400, 'form generation failure is rejected');
psCheck((int) psVal("SELECT COUNT(*) FROM payment_records WHERE channel='epay'") === 0, 'failed form generation rolls back attempt and does not lock identity');
$db->prepare("UPDATE system_settings SET value=? WHERE `key`='payment.epay'")->execute([$validRow['value']]);
$beforeFirst = psGet();
$form = psForm($invoice);
$state = psGet();
psCheck($state['identity_locked'] && $state['version'] !== $beforeFirst['version'], 'first committed attempt locks identity and invalidates old version');
$stale = psBody($beforeFirst);
$stale['endpoint'] = 'https://other.example.test';
psCheck(psSave($stale)[0] === 409, 'pre-attempt edit is stale after cashier admission');
foreach ([['endpoint' => 'https://other.example.test'], ['pid' => 'other'], ['key_action' => 'replace', 'key' => 'PS_FINAL_PRIVATE_KEY']] as $change) {
    psCheck(psSave(array_replace(psBody(), $change))[0] === 409, 'any merchant identity replacement rejected after attempt');
}
$extraInvoice = psInvoice('extra');
$extraForm = psForm($extraInvoice);
$cancelInvoice = psInvoice('cancel');
$cancelForm = psForm($cancelInvoice);
(new BillingService())->cancel($uid, $cancelInvoice);
$cloud = (new BillingService())->checkout($uid, ['product_id' => (new DemoService())->seed(), 'hostname' => 'ps-test', 'password' => 'PsFixture!2026', 'billing_cycle' => 'monthly', 'use_balance' => false]);
$cloudForm = psForm((int) $cloud['invoice_id']);
$body = psBody();
$body['enabled'] = false;
$body['notify_url'] = 'https://shop-next.example.test/api/v1/payments/epay/notify';
$body['return_url'] = 'https://shop-next.example.test';
psCheck(psSave($body)[0] === 200, 'disable and change site URLs while retaining frozen merchant identity');
$balance = (string) psVal('SELECT balance FROM users WHERE id=?', [$uid]);
foreach ([[$form, 'ps-normal'], [$form, 'ps-normal'], [$extraForm, 'ps-extra-normal'], [$extraForm, 'ps-extra-additional'], [$extraForm, 'ps-extra-additional'], [$cancelForm, 'ps-cancelled'], [$cancelForm, 'ps-cancelled'], [$cloudForm, 'ps-cloud'], [$cloudForm, 'ps-cloud']] as [$f,$trade]) {
    psCheck(array_slice(psHttp('/payments/epay/notify', null, 'POST', psNotice($f, $trade)), 0, 2) === [200, 'success'], 'disabled channel still accepts valid old receipt '.$trade);
}
psCheck(psVal('SELECT balance FROM users WHERE id=?', [$uid]) === bcadd($balance, '4.00', 2), 'normal, late and additional receipts each credit once');
psCheck((int) psVal('SELECT COUNT(*) FROM transactions WHERE invoice_id IN (?,?,?)', [$invoice, $extraInvoice, $cancelInvoice]) === 4, 'duplicate callbacks do not append ledger entries');
psCheck(psVal('SELECT status FROM invoices WHERE id=?', [$cancelInvoice]) === 'cancelled', 'cancelled original remains cancelled');
psCheck((int) psVal('SELECT COUNT(*) FROM tasks WHERE invoice_id=?', [$cloud['invoice_id']]) === 1, 'old cloud cashier delivers exactly one task after disable');
psCheck((int) psVal('SELECT COUNT(*) FROM tasks WHERE invoice_id IN (?,?,?)', [$invoice, $extraInvoice, $cancelInvoice]) === 0, 'recharge and late funds never enqueue delivery');
$bad = psNotice($form, 'ps-forged');
$bad['money'] = '100';
psCheck(psHttp('/payments/epay/notify', null, 'POST', $bad)[0] === 400, 'disabled channel still rejects forged signatures');
$foreign = psNotice($form, 'ps-foreign');
$foreign['pid'] = 'wrong';
$foreign['sign'] = \Plugins\payments\epay\EpayDriver::sign($foreign, 'PS_FINAL_PRIVATE_KEY');
psCheck(psHttp('/payments/epay/notify', null, 'POST', $foreign)[0] === 400, 'disabled channel verifies original merchant');
$new = psInvoice('disabled-again');
psCheck(psHttp('/invoices/'.$new.'/pay', $customer['token'], 'POST', ['gateway' => 'epay'])[0] === 409, 'new cashier remains unavailable after receipts');
$before = psRow();
$audit = (int) psVal("SELECT COUNT(*) FROM admin_audit_logs WHERE action='payment_configure'");
$db->exec("CREATE TRIGGER payment_settings_audit_fault BEFORE INSERT ON admin_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='ISOLATED AUDIT FAULT'");
try {
    $body = psBody();
    $body['enabled'] = true;
    psCheck(psSave($body)[0] === 500, 'audit failure rejects save');
} finally {
    $db->exec('DROP TRIGGER payment_settings_audit_fault');
}
psCheck(psRow() === $before && (int) psVal("SELECT COUNT(*) FROM admin_audit_logs WHERE action='payment_configure'") === $audit, 'audit failure rolls back complete setting');
$auditText = json_encode($db->query("SELECT context FROM admin_audit_logs WHERE action='payment_configure'")->fetchAll());
psCheck(! str_contains($auditText, 'PRIVATE_KEY') && ! str_contains($auditText, $cipher), 'audit records intent and public values only');

// Disable holds the row before checkout: no attempt may be admitted afterwards.
$body = psBody();
$body['enabled'] = true;
psCheck(psSave($body)[0] === 200, 'enable for concurrency acceptance');
$body = psBody();
$body['enabled'] = false;
$race = psInvoice('disable-first');
$child = null;
Database::transaction(function (PDO $db) use ($admin, $body, $race, $uid, &$child): void {
    (new PaymentSettingsService())->save($admin, $body);
    $child = psSpawn(['op' => 'checkout', 'user' => $uid, 'invoice' => $race]);
    psWaiting($child);
});
$result = psFinish($child);
psCheck($result['status'] === 409 && (int) psVal('SELECT COUNT(*) FROM payment_records WHERE invoice_id=?', [$race]) === 0, 'disable-first blocks waiting cashier with no attempt');
// Checkout holds the row first: its exact form survives the later disable.
$body = psBody();
$body['enabled'] = true;
psSave($body);
$body = psBody();
$body['enabled'] = false;
$race = psInvoice('checkout-first');
$child = null;
$raceForm = null;
Database::transaction(function (PDO $db) use ($admin, $body, $race, $uid, &$child, &$raceForm): void {
    $raceForm = psForm($race);
    $child = psSpawn(['op' => 'save', 'admin' => $admin, 'body' => $body]);
    psWaiting($child);
});
$result = psFinish($child);
psCheck($result['status'] === 200 && ! psGet()['enabled'] && (int) psVal('SELECT COUNT(*) FROM payment_records WHERE invoice_id=?', [$race]) === 1, 'checkout-first admits exactly one form before disable');
$n = psNotice($raceForm, 'ps-race-receipt');
(new PaymentService())->notify('epay', $n);
(new PaymentService())->notify('epay', $n);
psCheck(psVal('SELECT status FROM invoices WHERE id=?', [$race]) === 'paid' && (int) psVal('SELECT COUNT(*) FROM transactions WHERE invoice_id=?', [$race]) === 1, 'checkout-first old form settles once while disabled');
// Different super-admins, one revision, one committed configuration.
$second = (new AdminAuthService())->create(['username' => 'payment-settings-second', 'email' => 'payment-settings-second@example.test', 'password' => 'PaymentSettings!2026', 'role' => 'super_admin']);
$body = psBody();
$body['enabled'] = true;
$child = null;
Database::transaction(function (PDO $db) use ($admin, $second, $body, &$child): void {
    (new PaymentSettingsService())->save($admin, $body);
    $child = psSpawn(['op' => 'save', 'admin' => $second, 'body' => $body]);
    psWaiting($child);
});
psCheck(psFinish($child)['status'] === 409, 'different administrators cannot both save the same revision');
// Unknown/missing merchant identity cannot be silently recreated around historical attempts.
$saved = psRow();
$db->exec("DELETE FROM system_settings WHERE `key`='payment.epay'");
$state = psGet();
$create = psBody($state);
$create['key_action'] = 'replace';
$create['key'] = 'arbitrary';
$create['endpoint'] = 'https://fake.example.test';
$create['pid'] = 'fake';
$create['notify_url'] = 'https://shop.example.test/api/v1/payments/epay/notify';
$create['return_url'] = 'https://shop.example.test';
psCheck(psSave($create)[0] === 409, 'missing config plus historical attempts cannot establish another merchant');
try {
    Database::transaction(fn (PDO $db) => (new PaymentSettingsService())->installInitial($db, ['driver' => 'epay', 'endpoint' => $create['endpoint'], 'pid' => 'fake', 'notify_url' => $create['notify_url'], 'return_url' => $create['return_url'], 'credentials' => ['key' => 'fake']]));
    throw new RuntimeException('CLI must reject');
} catch (ApiException $e) {
    psCheck($e->httpStatus === 409, 'CLI cannot bypass historical identity lock');
}
$db->prepare("INSERT INTO system_settings(`key`,`group`,value,encrypted_credentials) VALUES('payment.epay','payment',?,?)")->execute([$saved['value'], $saved['encrypted_credentials']]);
$db->prepare("UPDATE admins SET role='support' WHERE id=?")->execute([$admin]);
psCheck(psHttp($root, $token)[0] === 403 && psSave(psBodyForDemoted($saved))[0] === 403, 'old token loses config access after role change');
function psBodyForDemoted(array $row): array
{
    return json_decode($row['value'], true) + ['expected_version' => str_repeat('0', 64), 'key_action' => 'keep'];
}
// This suite proves payment admission, not delivery; do not leave its synthetic task for another suite's Worker.
$db->prepare("UPDATE tasks SET status='failed',last_error='PAYMENT_SETTINGS_FIXTURE_NOT_EXECUTED' WHERE invoice_id=? AND action='create'")->execute([$cloud['invoice_id']]);
echo 'Admin payment settings checks: '.$checks.PHP_EOL;
