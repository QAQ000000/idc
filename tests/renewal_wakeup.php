<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use App\Core\{Database, RedisStore, Security};
use App\Services\{AuthService, BillingService, DemoService, LedgerService, OutboxWorker, PaymentService, RenewalService, RenewalWakeupService, WalletService};
use Illuminate\Support\Facades\DB;

if (config('app.env') !== 'test' || config('database.connections.mysql.database') !== 'jianyun_test') {
    throw new RuntimeException('Disposable database required');
}
set_exception_handler(static function (Throwable $e): never {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");
    exit(1);
});
$db = Database::getInstance();
$checks = 0;
$fixture = 0;
function wakeCheck(bool $ok, string $why): void
{
    global $checks;
    if (! $ok) {
        throw new RuntimeException('FAIL wake-up: '.$why);
    }
    $checks++;
    echo "PASS wake-up: $why\n";
}
function wakeValue(string $sql, array $args = []): mixed
{
    $s = Database::getInstance()->prepare($sql);
    $s->execute($args);

    return $s->fetchColumn();
}
function wakeKeys(): array
{
    return (new RedisStore())->command('KEYS', 'jianyun:renewals:wakeup:*') ?: [];
}
function wakeClear(): void
{
    foreach (wakeKeys() as $key) {
        (new RedisStore())->command('DEL', $key);
    }
}
function wakeMembers(): array
{
    $members = [];
    foreach (wakeKeys() as $key) {
        array_push($members, ...((new RedisStore())->command('SMEMBERS', $key) ?: []));
    }
    sort($members);

    return $members;
}
function wakeUpstream(string $path = '/__state'): array
{
    return json_decode(file_get_contents(getenv('UPSTREAM_TEST_URL').$path), true, 32, JSON_THROW_ON_ERROR);
}
function wakeFixture(): array
{
    global $fixture, $db;
    $fixture++;
    $session = (new AuthService())->register(['email' => 'wakeup-'.$fixture.'@example.test', 'password' => 'WakeupFixture!2026']);
    $uid = (int) $session['user']['id'];
    (new DemoService())->recharge($uid, '0.01', 'wake-seed-'.$fixture);
    $pid = (int) wakeValue("SELECT p.id FROM products p JOIN providers r ON r.id=p.provider_id WHERE r.name='renewal-fixture' AND p.remote_pid=391 LIMIT 1");
    $v = (new BillingService())->checkout($uid, ['product_id' => $pid, 'hostname' => 'wake-fixture', 'password' => 'WakeupFixture12', 'billing_cycle' => 'monthly', 'use_balance' => true]);
    (new OutboxWorker())->runOnce('wake-create');
    if (wakeValue('SELECT status FROM instances WHERE id=?', [$v['instance_id']]) !== 'active') {
        throw new RuntimeException('CDN fixture not delivered');
    }
    $db->prepare('UPDATE instances SET due_date=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 2 DAY),auto_renew=1 WHERE id=?')->execute([$v['instance_id']]);
    $renew = (new RenewalService())->issue($uid, (int) $v['instance_id']);
    wakeClear();

    return ['user' => $uid, 'instance' => (int) $v['instance_id'], 'invoice' => (int) $renew['invoice_id'],
        'base' => wakeValue('SELECT due_date FROM instances WHERE id=?', [$v['instance_id']])];
}
function wakeNotice(array $f, string $suffix, string $amount = '0.01'): array
{
    $v = (new WalletService())->recharge($f['user'], ['amount' => $amount, 'request_key' => 'wake-recharge-'.$suffix]);
    $form = (new PaymentService())->checkout($f['user'], (int) $v['invoice_id'], 'epay')['checkout'];
    $notice = ['pid' => 'wallet-fixture', 'out_trade_no' => $form['fields']['out_trade_no'], 'trade_no' => 'wake-'.$suffix, 'money' => $form['fields']['money'], 'trade_status' => 'TRADE_SUCCESS', 'sign_type' => 'MD5'];
    $notice['sign'] = \Plugins\payments\epay\EpayDriver::sign($notice, 'wallet-fixture-key');

    return ['notice' => $notice, 'invoice' => (int) $v['invoice_id']];
}
function wakeParallelCallbacks(array $notices): void
{
    $multi = curl_multi_init();
    $handles = [];
    foreach ($notices as $n) {
        $c = curl_init(getenv('TEST_BASE_URL').'/api/v1/payments/epay/notify');
        curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($n), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
        curl_multi_add_handle($multi, $c);
        $handles[] = $c;
    }
    do {
        curl_multi_exec($multi, $running);
        if ($running) {
            curl_multi_select($multi, 1);
        }
    } while ($running);
    $ok = true;
    foreach ($handles as $c) {
        $ok = $ok && curl_getinfo($c, CURLINFO_RESPONSE_CODE) === 200 && curl_multi_getcontent($c) === 'success';
        curl_multi_remove_handle($multi, $c);
        curl_close($c);
    }
    curl_multi_close($multi);
    wakeCheck($ok, 'parallel verified callbacks are acknowledged');
}
function wakeCommand(array $arguments, ?array $environment = null): void
{
    $process = proc_open([PHP_BINARY, dirname(__DIR__).'/artisan', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $environment);
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start isolated Artisan');
    }
    $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Isolated Artisan failed: '.$out);
    }
}
function wakeRace(int $user): void
{
    $gate = tempnam(sys_get_temp_dir(), 'jianyun-wake-gate-');
    unlink($gate);
    $children = [];
    try {
        foreach (['wake', 'scan', 'wake', 'scan'] as $mode) {
            $body = 'require '.var_export(dirname(__DIR__).'/bootstrap.php', true).'; $end=microtime(true)+10; while(!file_exists('.var_export($gate, true).')){if(microtime(true)>$end)exit(2);usleep(1000);} '.
                ($mode === 'wake' ? '(new App\\Services\\RenewalWakeupService())->runOnce();' : '(new App\\Services\\RenewalService())->debitDue('.$user.');');
            $process = proc_open([PHP_BINARY, '-r', $body], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
            if (! is_resource($process)) {
                throw new RuntimeException('Cannot start competing debit');
            }
            $children[] = [$process, $pipes];
        }
        touch($gate);
        foreach ($children as [$process,$pipes]) {
            $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_close($process) !== 0) {
                throw new RuntimeException('Competing debit failed: '.$output);
            }
        }
        $children = [];
    } finally {
        foreach ($children as [$process,$pipes]) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
        @unlink($gate);
    }
}
function wakePaidOnce(array $f, string $balance = '0.00'): bool
{
    return wakeValue('SELECT status FROM invoices WHERE id=?', [$f['invoice']]) === 'paid'
        && (int) wakeValue("SELECT COUNT(*) FROM transactions WHERE invoice_id=? AND type='pay_invoice'", [$f['invoice']]) === 1
        && (int) wakeValue("SELECT COUNT(*) FROM tasks WHERE invoice_id=? AND action='renew'", [$f['invoice']]) === 1
        && wakeValue('SELECT balance FROM users WHERE id=?', [$f['user']]) === $balance
        && wakeValue('SELECT due_date FROM instances WHERE id=?', [$f['instance']]) === wakeValue('SELECT DATE_FORMAT(DATE_ADD(?, INTERVAL 1 MONTH),\'%Y-%m-%d %H:%i:%s\')', [$f['base']]);
}

$db->exec("UPDATE tasks SET status='success',lease_until=NULL WHERE status IN ('pending','running','investigating')");
$db->exec('UPDATE instances SET auto_renew=0');
wakeUpstream('/__mode?mode=normal');
wakeClear();
$f = wakeFixture();
$notice = wakeNotice($f, 'outer-rollback');
try {
    Database::transaction(function () use ($notice): void {
        (new PaymentService())->notify('epay', $notice['notice']);
        wakeCheck(wakeMembers() === [], 'inner recharge commit publishes no hint before the outer commit');
        throw new RuntimeException('intentional outer rollback');
    });
} catch (RuntimeException $e) {
    if ($e->getMessage() !== 'intentional outer rollback') {
        throw $e;
    }
}
wakeCheck(wakeMembers() === [] && wakeValue('SELECT balance FROM users WHERE id=?', [$f['user']]) === '0.00'
    && wakeValue('SELECT status FROM invoices WHERE id=?', [$notice['invoice']]) === 'unpaid', 'outer rollback removes funds and its deferred hint together');
// A rolled-back nested savepoint cannot leak its callback when the outer transaction commits.
Database::transaction(function () use ($notice): void {
    try {
        Database::transaction(function () use ($notice): void {
            (new PaymentService())->notify('epay', $notice['notice']);
            throw new RuntimeException('savepoint rollback');
        });
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== 'savepoint rollback') {
            throw $e;
        }
    }
});
wakeCheck(wakeMembers() === [], 'savepoint rollback drops its hint despite a later outer commit');
(new PaymentService())->notify('epay', $notice['notice']);
wakeCheck(wakeMembers() === [(string) $f['user']] && wakeValue('SELECT status FROM invoices WHERE id=?', [$f['invoice']]) === 'unpaid'
    && wakeValue('SELECT balance FROM users WHERE id=?', [$f['user']]) === '0.01', 'committed recharge only signals its customer and never debits in the callback');
wakeCheck((int) wakeValue('SELECT COUNT(*) FROM tasks WHERE invoice_id=?', [$notice['invoice']]) === 0, 'pure recharge still has no resource Outbox');
(new RenewalWakeupService())->runOnce();
wakeCheck(wakePaidOnce($f), 'asynchronous wake settles one original invoice, debit, renew task and period');
$prior = wakeUpstream();
wakeCheck($prior['renew_calls'] === 0, 'wake and callback never execute upstream renewal');
(new PaymentService())->notify('epay', $notice['notice']);
wakeCheck(wakeMembers() === [], 'duplicate settled callback does not republish a hint');
(new OutboxWorker())->runOnce('wake-renew');
$after = wakeUpstream();
wakeCheck($after['renew_calls'] === 1 && $after['renew_payments'] === 1 && wakeValue('SELECT status FROM tasks WHERE invoice_id=?', [$f['invoice']]) === 'success', 'original fenced Worker confirms exactly one CDN renewal');

$f = wakeFixture();
$n = wakeNotice($f, 'duplicates');
wakeParallelCallbacks(array_fill(0, 8, $n['notice']));
wakeCheck(wakeMembers() === [(string) $f['user']] && wakeValue('SELECT balance FROM users WHERE id=?', [$f['user']]) === '0.01'
    && (int) wakeValue('SELECT COUNT(*) FROM transactions WHERE invoice_id=?', [$n['invoice']]) === 1, 'concurrent duplicate callbacks credit once and coalesce one customer hint');
wakeRace($f['user']);
wakeCheck(wakePaidOnce($f), 'competing wake consumers and scheduled debit settle once');
(new OutboxWorker())->runOnce('wake-race');

$f = wakeFixture();
$a = wakeNotice($f, 'parallel-a', '0.02');
$b = wakeNotice($f, 'parallel-b', '0.03');
wakeParallelCallbacks([$a['notice'], $b['notice'], $a['notice'], $b['notice']]);
wakeCheck(wakeMembers() === [(string) $f['user']] && wakeValue('SELECT balance FROM users WHERE id=?', [$f['user']]) === '0.05', 'distinct concurrent receipts add their exact amounts while merging the hint');
wakeRace($f['user']);
wakeCheck(wakePaidOnce($f, '0.04'), 'multiple deposits and wake/scan competition buy only the original period');
(new OutboxWorker())->runOnce('wake-multi');

$f = wakeFixture();
$n = wakeNotice($f, 'deadlock-retry');
$db->exec('SET @wake_deadlock_once=0');
$db->exec("CREATE TRIGGER wake_deadlock_fault BEFORE INSERT ON transactions FOR EACH ROW BEGIN IF NEW.invoice_id=".$n['invoice']." AND @wake_deadlock_once=0 THEN SET @wake_deadlock_once=1; SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO=1213, MESSAGE_TEXT='isolated deadlock'; END IF; END");
try {
    (new PaymentService())->notify('epay', $n['notice']);
} finally {
    $db->exec('DROP TRIGGER wake_deadlock_fault');
}
wakeCheck(wakeMembers() === [(string) $f['user']] && wakeValue('SELECT balance FROM users WHERE id=?', [$f['user']]) === '0.01'
    && (int) wakeValue('SELECT COUNT(*) FROM transactions WHERE invoice_id=?', [$n['invoice']]) === 1, 'database deadlock retry rolls back partial settlement and publishes only the committed recharge hint');
(new RenewalWakeupService())->runOnce();
(new OutboxWorker())->runOnce('wake-deadlock');

$f = wakeFixture();
$n = wakeNotice($f, 'worker-loop');
(new PaymentService())->notify('epay', $n['notice']);
wakeCommand(['outbox:work', '--once']);
wakeCheck(wakePaidOnce($f) && wakeValue('SELECT status FROM tasks WHERE invoice_id=?', [$f['invoice']]) === 'success', 'existing Artisan Worker loop consumes the hint then executes the original renew Outbox');

// Each hint is a customer-scoped scan, not a global debit or invoice generator.
$f = wakeFixture();
$other = wakeFixture();
$a = wakeNotice($f, 'scoped');
$b = wakeNotice($other, 'other');
(new PaymentService())->notify('epay', $b['notice']);
wakeClear();
(new PaymentService())->notify('epay', $a['notice']);
(new RenewalWakeupService())->runOnce();
wakeCheck(wakePaidOnce($f) && wakeValue('SELECT status FROM invoices WHERE id=?', [$other['invoice']]) === 'unpaid'
    && wakeValue('SELECT balance FROM users WHERE id=?', [$other['user']]) === '0.01', 'wake only examines the credited customer existing renewal invoices');
(new OutboxWorker())->runOnce('wake-scoped');
(new RenewalService())->debitDue($other['user']);
(new OutboxWorker())->runOnce('wake-other');

// Lost hints and crash after pop are harmless: the unchanged MySQL scan is the recovery path.
foreach (['lost', 'popped'] as $mode) {
    $f = wakeFixture();
    $n = wakeNotice($f, $mode);
    (new PaymentService())->notify('epay', $n['notice']);
    if ($mode === 'lost') {
        wakeClear();
    } else {
        foreach (wakeKeys() as $key) {
            (new RedisStore())->command('SPOP', $key);
        }
    }
    (new RenewalService())->debitDue($f['user']);
    wakeCheck(wakePaidOnce($f), 'scheduled scan recovers a '.$mode.' wake without recreating or extending twice');
    (new OutboxWorker())->runOnce('wake-fallback');
}

$f = wakeFixture();
$n = wakeNotice($f, 'late-recharge');
(new BillingService())->cancel($f['user'], $n['invoice']);
(new PaymentService())->notify('epay', $n['notice']);
wakeCheck(wakeMembers() === [] && wakeValue('SELECT balance FROM users WHERE id=?', [$f['user']]) === '0.01'
    && wakeValue('SELECT status FROM invoices WHERE id=?', [$f['invoice']]) === 'unpaid', 'late recharge receipt keeps its own wallet purpose and does not request early debit');
(new RenewalService())->debitDue($f['user']);
(new OutboxWorker())->runOnce('wake-late-fallback');

// Failure to publish after a successful database commit must not become callback failure.
$f = wakeFixture();
$n = wakeNotice($f, 'redis-outage');
$redisConfig = config('database.redis.default');
try {
    config(['database.redis.default.password' => 'invalid-isolated-wakeup-password']);
    app()->forgetInstance('redis');
    \Illuminate\Support\Facades\Redis::clearResolvedInstance('redis');
    (new PaymentService())->notify('epay', $n['notice']);
} finally {
    config(['database.redis.default' => $redisConfig]);
    app()->forgetInstance('redis');
    \Illuminate\Support\Facades\Redis::clearResolvedInstance('redis');
}
wakeCheck(wakeMembers() === [] && wakeValue('SELECT status FROM invoices WHERE id=?', [$n['invoice']]) === 'paid'
    && wakeValue('SELECT balance FROM users WHERE id=?', [$f['user']]) === '0.01', 'Redis publish failure preserves committed recharge and successful callback result');
wakeCommand(['renewals:debit']);
wakeCheck(wakePaidOnce($f), 'unchanged global debit command recovers an unavailable Redis signal');
wakeCommand(['outbox:work', '--once'], array_merge(getenv(), ['REDIS_PASSWORD' => 'invalid-isolated-wakeup-password']));
wakeCheck(wakeValue('SELECT status FROM tasks WHERE invoice_id=?', [$f['invoice']]) === 'success', 'Redis consumer failure cannot block the existing Outbox execution');
// Continue in fresh worker processes, avoiding the producer failure cooldown in this test process.

foreach (['disabled', 'insufficient', 'cancelled', 'outside-window', 'manual-pause', 'expired-lease', 'refund-freeze', 'stale-period', 'suspended-user'] as $mode) {
    $f = wakeFixture();
    $n = wakeNotice($f, 'guard-'.$mode);
    if ($mode === 'disabled') {
        (new RenewalService())->setAutomatic($f['user'], $f['instance'], false);
    }
    if ($mode === 'insufficient') {
        $db->prepare('UPDATE invoices SET total=\'0.02\',price_snapshot=JSON_SET(price_snapshot,\'$.total\',\'0.02\') WHERE id=?')->execute([$f['invoice']]);
        $db->prepare('UPDATE instances SET recurring_price=\'0.02\' WHERE id=?')->execute([$f['instance']]);
    }
    if ($mode === 'cancelled') {
        (new BillingService())->cancel($f['user'], $f['invoice']);
    }
    if ($mode === 'outside-window') {
        $db->prepare('UPDATE instances SET due_date=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 20 DAY) WHERE id=?')->execute([$f['instance']]);
    }
    if ($mode === 'manual-pause') {
        $db->prepare("UPDATE instances SET status='suspended',suspend_type='manual' WHERE id=?")->execute([$f['instance']]);
    }
    if ($mode === 'expired-lease') {
        $db->prepare("INSERT INTO tasks(instance_id,action,status,idempotency_key,payload,execution_token,lease_until) VALUES(?,'power','investigating',?,'{}',7,DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY))")->execute([$f['instance'], 'wake-gate-'.$f['instance']]);
    }
    if ($mode === 'refund-freeze') {
        $db->prepare("UPDATE invoices SET status='refund_pending' WHERE instance_id=? AND type='new'")->execute([$f['instance']]);
    }
    if ($mode === 'stale-period') {
        $db->prepare('UPDATE instances SET due_date=DATE_ADD(due_date,INTERVAL 1 DAY) WHERE id=?')->execute([$f['instance']]);
    }
    if ($mode === 'suspended-user') {
        $db->prepare("UPDATE users SET status='suspended' WHERE id=?")->execute([$f['user']]);
    }
    $base = wakeValue('SELECT due_date FROM instances WHERE id=?', [$f['instance']]);
    wakeParallelCallbacks([$n['notice']]);
    wakeCommand(['outbox:work', '--once']);
    wakeCheck(wakeValue('SELECT balance FROM users WHERE id=?', [$f['user']]) === '0.01'
        && wakeValue('SELECT status FROM invoices WHERE id=?', [$f['invoice']]) === ($mode === 'cancelled' ? 'cancelled' : 'unpaid')
        && wakeValue('SELECT due_date FROM instances WHERE id=?', [$f['instance']]) === $base
        && (int) wakeValue("SELECT COUNT(*) FROM invoices WHERE instance_id=? AND type='renew'", [$f['instance']]) === 1
        && (int) wakeValue("SELECT COUNT(*) FROM tasks WHERE invoice_id=? AND action='renew'", [$f['invoice']]) === 0, 'wake preserves funds, original period and gate: '.$mode);
    if ($mode === 'expired-lease') {
        $db->prepare("UPDATE tasks SET status='success' WHERE instance_id=? AND action='power'")->execute([$f['instance']]);
    }
}
wakeClear();
echo "Renewal wake-up checks: $checks\n";
