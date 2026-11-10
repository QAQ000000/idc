<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
set_exception_handler(static function (Throwable $e): never {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");
    exit(1);
});
use App\Core\{ApiException,Database,Security};
use App\Services\{AdminAccountService,AdminAuthService,AuthService};
use Illuminate\Support\Facades\RateLimiter;

if (config('app.env') !== 'test' || config('database.connections.mysql.database') !== 'jianyun_test') {
    throw new RuntimeException('Isolated database required');
}
$db = Database::getInstance();
$service = new AdminAccountService();
$auth = new AdminAuthService();
$checks = 0;
function acOperation(array $v): array
{
    global $service;

    return $service->update($v['actor'], $v['session'], $v['target'], $v['input']);
}
if (($argv[1] ?? '') === '--child') {
    $v = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
    echo 'READY:'.$db->query('SELECT CONNECTION_ID()')->fetchColumn()."\n";
    fflush(STDOUT);
    try {
        echo json_encode(['status' => 200, 'data' => acOperation($v)], JSON_THROW_ON_ERROR)."\n";
    } catch (ApiException $e) {
        echo json_encode(['status' => $e->httpStatus], JSON_THROW_ON_ERROR)."\n";
    }exit;
}
function acCheck(bool $ok, string $label): void
{
    global $checks;
    if (! $ok) {
        throw new RuntimeException('FAIL accounts: '.$label);
    }$checks++;
    echo "PASS accounts: $label\n";
}
function acValue(string $sql, array $args = []): mixed
{
    global $db;
    $s = $db->prepare($sql);
    $s->execute($args);

    return $s->fetchColumn();
}
function acReject(callable $f, int $status, string $label): void
{
    try {
        $f();
    } catch (ApiException $e) {
        acCheck($e->httpStatus === $status, $label);

        return;
    }throw new RuntimeException('Expected rejection: '.$label);
}
function acSession(int $id): int
{
    global $db;

    return AdminAuthService::sessionVersion($db, $id);
}
function acToken(int $id): string
{
    return Security::generateToken(['uid' => $id, 'sub_type' => 'admin', 'av' => acSession($id)]);
}
function acHttp(string $path, ?string $token = null, string $method = 'GET', ?array $body = null): array
{
    $c = curl_init(getenv('TEST_BASE_URL').'/api/v1'.$path);
    $cache = '';
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => array_filter(['Content-Type: application/json', $token ? 'Authorization: Bearer '.$token : null]), CURLOPT_HEADERFUNCTION => static function ($c, $line) use (&$cache) {
        if (str_starts_with(strtolower($line), 'cache-control:')) {
            $cache = trim(substr($line, 14));
        }

        return strlen($line);
    }]);
    if ($body !== null) {
        curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($body ?: new stdClass(), JSON_THROW_ON_ERROR));
    }$raw = curl_exec($c);
    $status = curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);

    return [$status, json_decode($raw, true, 64, JSON_THROW_ON_ERROR), $cache];
}
function acVersion(int $actor, int $id): string
{
    global $service;

    return $service->detail($actor, acSession($actor), $id)['account']['version'];
}
function acInput(int $actor, int $target, string $role = 'support', string $status = 'active'): array
{
    return ['role' => $role, 'status' => $status, 'expected_version' => acVersion($actor, $target), 'evidence' => 'ACCOUNTS-ACCEPTANCE: authorized staffing change'];
}
function acSpawn(array $v): array
{
    $p = proc_open([PHP_BINARY, __FILE__, '--child'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__));
    fwrite($pipes[0], json_encode($v, JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    stream_set_timeout($pipes[1], 15);
    $ready = trim(fgets($pipes[1]) ?: '');
    if (! preg_match('/^READY:([0-9]+)$/D', $ready, $m)) {
        throw new RuntimeException('Child connection not ready');
    }

    return [$p, $pipes, (int) $m[1]];
}
function acWaiting(array $child): void
{
    global $db;
    $deadline = microtime(true) + 8;
    do {
        $s = $db->prepare('SELECT trx_state FROM information_schema.innodb_trx WHERE trx_mysql_thread_id=?');
        $s->execute([$child[2]]);
        if ($s->fetchColumn() === 'LOCK WAIT') {
            return;
        }usleep(100000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('No real account lock contention observed');
}
function acFinish(array $child): array
{
    [$p,$pipes] = $child;
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($p) !== 0) {
        throw new RuntimeException('Child failed: '.$err.$out);
    }

    return json_decode(trim($out), true, 32, JSON_THROW_ON_ERROR);
}
function acFacts(): array
{
    global $db;
    $facts = [];
    foreach (['users', 'invoices', 'payment_records', 'transactions', 'instances', 'tasks'] as $t) {
        $facts[$t] = $db->query('SELECT * FROM '.$t.' ORDER BY id')->fetchAll();
    }

    return $facts;
}
$ids = [];
foreach (AdminAccountService::ROLES as $role) {
    $ids[$role] = $auth->create(['username' => 'accounts-'.$role, 'email' => 'accounts-'.$role.'@example.test', 'role' => $role, 'password' => 'AccountFixture!2026']);
}
$aid = $ids['super_admin'];
$at = acToken($aid);
$sid = $ids['support'];
$st = acToken($sid);
$client = (new AuthService())->register(['email' => 'accounts-client@example.test', 'password' => 'AccountClient!2026'])['token'];
$facts = acFacts();
acCheck(acSession($aid) === 0 && acHttp('/admin/auth/me', Security::generateToken(['uid' => $aid, 'sub_type' => 'admin']))[0] === 200, 'legacy token accepted only at revocation version zero');
foreach (['0', null, -1] as $bad) {
    acCheck(acHttp('/admin/auth/me', Security::generateToken(['uid' => $aid, 'sub_type' => 'admin', 'av' => $bad]))[0] === 401, 'malformed or mismatched session version rejected');
}
$create = ['username' => 'accounts-created', 'email' => 'accounts-created@example.test', 'role' => 'support', 'status' => 'active', 'password' => 'AccountCreated!2026'];
foreach (['/admin/accounts', '/admin/accounts/'.$aid] as $path) {
    acCheck(acHttp($path)[0] === 401 && acHttp($path, $client)[0] === 401, 'anonymous and customer cannot read admin accounts');
}
foreach (['finance', 'support', 'operator'] as $role) {
    $t = acToken($ids[$role]);
    acCheck(acHttp('/admin/accounts', $t)[0] === 403 && acHttp('/admin/accounts/'.$aid, $t)[0] === 403, 'non-super role cannot read account directory');
    acCheck(acHttp('/admin/accounts', $t, 'POST', $create)[0] === 403 && acHttp('/admin/accounts/'.$aid, $t, 'PATCH', [])[0] === 403 && acHttp('/admin/accounts/'.$aid.'/password', $t, 'POST', [])[0] === 403, 'non-super role cannot create modify or reset');
    acCheck(acHttp('/admin/auth/security', $t)[0] === 200, 'all enabled roles can read own security');
}
[$status,$created,$cache] = acHttp('/admin/accounts', $at, 'POST', $create);
$id = (int) ($created['data']['admin_id'] ?? 0);
acCheck($status === 200 && $id > 0 && str_contains($cache, 'no-store'), 'super creates admin with non-cacheable response');
acCheck(! str_contains(json_encode($created), $create['password']) && acSession($id) > 0, 'creation returns no password and establishes session version');
acCheck(acHttp('/admin/accounts', $at, 'POST', $create)[0] === 409, 'duplicate create cannot create another account');
acCheck(acHttp('/admin/accounts', $at, 'POST', array_replace($create, ['username' => 'unique', 'password' => 'short']))[0] === 400, 'weak initial password rejected');
acCheck(acHttp('/admin/accounts', $at, 'POST', array_replace($create, ['username' => 'unique', 'role' => 'custom']))[0] === 400, 'only four fixed roles accepted');
acCheck(acHttp('/admin/accounts', $at, 'POST', $create + ['token' => 'unsupported'])[0] === 400, 'unknown writable field rejected');
$login = $auth->login(['username' => $create['username'], 'password' => $create['password']]);
$old = $login['token'];
acCheck(Security::verifyToken($old)['av'] === acSession($id) && acHttp('/admin/auth/me', $old)[0] === 200, 'newly created account can log in with versioned token');
acCheck(acHttp('/admin/auth/me', Security::generateToken(['uid' => $id, 'sub_type' => 'admin']))[0] === 401, 'legacy token cannot bypass nonzero version');
[$status,$list,$cache] = acHttp('/admin/accounts?q=accounts-created&per_page=1', $at);
acCheck($status === 200 && $list['data']['total'] === 1 && count($list['data']['items']) === 1 && str_contains($cache, 'no-store'), 'account search pagination and no-store');
acCheck(acHttp('/admin/accounts?q=accounts-created&per_page=1&page=2', $at)[1]['data']['items'] === [], 'empty later page');
acCheck(acHttp('/admin/accounts?q=does-not-exist', $at)[1]['data']['total'] === 0, 'empty search');
acCheck(acHttp('/admin/accounts?q=accounts%25', $at)[1]['data']['total'] === 0, 'search wildcard treated as literal');
foreach (['page=0', 'per_page=101', 'role=custom', 'status[]=active', 'q[]=bad'] as $q) {
    acCheck(acHttp('/admin/accounts?'.$q, $at)[0] === 400, 'malformed filter rejected');
}
acCheck(acHttp('/admin/accounts/999999999', $at)[0] === 404, 'missing admin detail');
$detail = $service->detail($aid, 0, $id);
$encoded = json_encode($detail);
acCheck(! str_contains($encoded, 'password') && ! str_contains($encoded, 'two_factor_secret') && ! str_contains($encoded, $create['password']), 'directory detail and audit expose only safe projection');
$input = acInput($aid, $id, 'finance');
acCheck(acHttp('/admin/accounts/'.$id, $at, 'PATCH', $input + ['password' => 'not-allowed'])[0] === 400, 'role edit cannot write a password');
$service->update($aid, 0, $id, $input);
acCheck(acHttp('/admin/auth/me', $old)[0] === 401, 'role change revokes old session');
acReject(fn () => $service->update($aid, 0, $id, $input), 409, 'stale account version rejected');
$service->update($aid, 0, $id, acInput($aid, $id));
acReject(fn () => $service->update($aid, 0, $id, $input), 409, 'role A-B-A cannot revive old preview');
$enabledToken = acToken($id);
$service->update($aid, 0, $id, acInput($aid, $id, 'support', 'disabled'));
acCheck(acHttp('/admin/auth/me', $enabledToken)[0] === 403, 'disabled admin immediately loses access');
acReject(fn () => $auth->login(['username' => $create['username'], 'password' => $create['password']]), 403, 'disabled admin cannot log in');
$service->update($aid, 0, $id, acInput($aid, $id));
acCheck(acHttp('/admin/auth/me', $enabledToken)[0] === 401, 'reenable cannot restore previous token');
$current = acToken($id);
$reset = ['expected_version' => acVersion($aid, $id), 'new_password' => 'AccountReset!2026', 'evidence' => 'ACCOUNTS: explicit authorized reset', 'confirmed' => true];
acReject(fn () => $service->resetPassword($aid, 0, $id, array_replace($reset, ['confirmed' => false])), 400, 'reset requires explicit confirmation');
acReject(fn () => $service->resetPassword($aid, 0, $aid, $reset), 400, 'super cannot bypass own old password using reset');
$service->resetPassword($aid, 0, $id, $reset);
acReject(fn () => $service->resetPassword($aid, 0, $id, $reset), 409, 'repeated reset with original version rejected');
acCheck(acHttp('/admin/auth/me', $current)[0] === 401, 'password reset revokes old token');
acReject(fn () => $auth->login(['username' => $create['username'], 'password' => $create['password']]), 401, 'old password no longer logs in');
acCheck(isset($auth->login(['username' => $create['username'], 'password' => $reset['new_password']])['token']), 'new reset password works');
$change = ['current_password' => 'wrong-password', 'new_password' => 'AccountChanged!2026', 'expected_version' => $service->security($id, acSession($id))['account']['version']];
$before = acSession($id);
acReject(fn () => $service->changePassword($id, $before, $change), 400, 'self change verifies old password');
acCheck(acSession($id) === $before, 'wrong old password does not revoke sessions');
$current = acToken($id);
$change['current_password'] = $reset['new_password'];
acCheck(acHttp('/admin/auth/password', $current, 'POST', $change)[0] === 200 && acHttp('/admin/auth/me', $current)[0] === 401, 'self password change revokes own session');
acCheck(isset($auth->login(['username' => $create['username'], 'password' => $change['new_password']])['token']), 'new self password works');
RateLimiter::clear('admin-password:'.$id);
for ($n = 0; $n < 5; $n++) {
    RateLimiter::hit('admin-password:'.$id, 60);
}acReject(fn () => $service->changePassword($id, acSession($id), $change), 429, 'self change attempts rate limited');
RateLimiter::clear('admin-password:'.$id);
$audits = (string) acValue("SELECT JSON_ARRAYAGG(context) FROM admin_audit_logs WHERE target_type='admin' AND target_id=?", [$id]);
foreach ([$create['password'], $reset['new_password'], $change['new_password'], (string) acValue('SELECT password FROM admins WHERE id=?', [$id])] as $secret) {
    acCheck(! str_contains($audits, $secret), 'audit contains neither password nor hash');
}
$oldVersion = acVersion($aid, $id);
$auditCount = (int) acValue('SELECT COUNT(*) FROM admin_audit_logs');
$db->exec("CREATE TRIGGER admin_accounts_audit_failure BEFORE INSERT ON admin_audit_logs FOR EACH ROW BEGIN IF NEW.action='admin_account_update' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='ISOLATED_AUDIT_FAILURE'; END IF; END");
try {
    $service->update($aid, 0, $id, acInput($aid, $id, 'operator'));
    throw new RuntimeException('Audit failure did not reject');
} catch (PDOException $e) {
    acCheck(str_contains($e->getMessage(), 'ISOLATED_AUDIT_FAILURE'), 'audit failure propagated');
} finally {
    $db->exec('DROP TRIGGER admin_accounts_audit_failure');
}
acCheck(acVersion($aid, $id) === $oldVersion && acValue('SELECT role FROM admins WHERE id=?', [$id]) === 'support' && (int) acValue('SELECT COUNT(*) FROM admin_audit_logs') === $auditCount, 'audit failure rolls back role and revocation together');
$db->exec("CREATE TRIGGER admin_accounts_audit_failure BEFORE INSERT ON admin_audit_logs FOR EACH ROW BEGIN IF NEW.action IN ('admin_password_reset','admin_password_change','admin_account_create') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='ISOLATED_AUDIT_FAILURE'; END IF; END");
$hashBefore = acValue('SELECT password FROM admins WHERE id=?', [$id]);
$countBefore = (int) acValue('SELECT COUNT(*) FROM admins');
try {
    foreach ([
        fn () => $service->resetPassword($aid, 0, $id, array_replace($reset, ['expected_version' => acVersion($aid, $id)])),
        fn () => $service->changePassword($id, acSession($id), ['current_password' => $change['new_password'], 'new_password' => 'RollbackSecret!2026', 'expected_version' => $oldVersion]),
        fn () => $service->create($aid, 0, array_replace($create, ['username' => 'accounts-rollback', 'email' => 'accounts-rollback@example.test'])),
    ] as $operation) {
        try {
            $operation();
            throw new RuntimeException('Missing audit failure');
        } catch (PDOException $e) {
            acCheck(str_contains($e->getMessage(), 'ISOLATED_AUDIT_FAILURE'), 'password or creation audit failure propagates');
        }
    }
} finally {
    $db->exec('DROP TRIGGER admin_accounts_audit_failure');
}
acCheck(acValue('SELECT password FROM admins WHERE id=?', [$id]) === $hashBefore && acVersion($aid, $id) === $oldVersion && (int) acValue('SELECT COUNT(*) FROM admins') === $countBefore, 'failed audits roll back password changes and account creation');
// Deliberately restrict this disposable fixture to two available super administrators.
$original = $db->query("SELECT id,status FROM admins WHERE role='super_admin'")->fetchAll();
$db->exec("UPDATE admins SET status='disabled' WHERE role='super_admin'");
$db->prepare("UPDATE admins SET status='active' WHERE id=?")->execute([$aid]);
$bid = $auth->create(['username' => 'accounts-second-super', 'email' => 'accounts-second-super@example.test', 'role' => 'super_admin', 'password' => 'AccountSuper!2026']);
$db->prepare("UPDATE admins SET two_factor_secret='ISOLATED_2FA' WHERE id=?")->execute([$bid]);
acReject(fn () => $service->update($aid, acSession($aid), $aid, acInput($aid, $aid, 'super_admin', 'disabled')), 409, 'unsupported second factor is not a viable fallback super');
acCheck(acHttp('/admin/auth/me', acToken($bid))[0] === 401, 'existing token cannot bypass second factor');
$validHash = acValue('SELECT password FROM admins WHERE id=?', [$bid]);
$db->prepare('UPDATE admins SET two_factor_secret=NULL,password=? WHERE id=?')->execute(['invalid-hash', $bid]);
acReject(fn () => $service->update($aid, acSession($aid), $aid, acInput($aid, $aid, 'support')), 409, 'invalid password hash is not a viable fallback super');
$db->prepare('UPDATE admins SET password=? WHERE id=?')->execute([$validHash, $bid]);
foreach (['disable', 'demote'] as $scenario) {
    $aInput = acInput($aid, $aid, $scenario === 'disable' ? 'super_admin' : 'support', $scenario === 'disable' ? 'disabled' : 'active');
    $bInput = acInput($bid, $bid, $scenario === 'disable' ? 'super_admin' : 'support', $scenario === 'disable' ? 'disabled' : 'active');
    $child = Database::transaction(function (PDO $db) use ($aid, $bid, $aInput, $bInput): array {
        $db->query('SELECT id FROM admins ORDER BY id FOR UPDATE')->fetchAll();
        $c = acSpawn(['actor' => $bid, 'session' => acSession($bid), 'target' => $bid, 'input' => $bInput]);
        acWaiting($c);
        global $service;
        $service->update($aid, acSession($aid), $aid, $aInput);

        return $c;
    });
    acCheck(acFinish($child)['status'] === 409, 'real concurrent '.$scenario.' retains last loginable super');
    acCheck((int) acValue("SELECT COUNT(*) FROM admins WHERE role='super_admin' AND status='active' AND two_factor_secret IS NULL") === 1, 'one viable super remains after '.$scenario.' race');
    $service->update($bid, acSession($bid), $aid, acInput($bid, $aid, 'super_admin'));
}
// A request preauthorized before waiting must not write after its own session is revoked.
$bInput = acInput($bid, $id, 'operator');
$child = Database::transaction(function (PDO $db) use ($aid, $bid, $id, $bInput): array {
    $db->query('SELECT id FROM admins ORDER BY id FOR UPDATE')->fetchAll();
    $c = acSpawn(['actor' => $bid, 'session' => acSession($bid), 'target' => $id, 'input' => $bInput]);
    acWaiting($c);
    global $service;
    $service->update($aid, acSession($aid), $bid, acInput($aid, $bid, 'support'));

    return $c;
});
acCheck(acFinish($child)['status'] === 401 && acValue('SELECT role FROM admins WHERE id=?', [$id]) === 'support', 'session revoked while waiting cannot authorize account write');
acCheck(acHttp('/admin/auth/me', $st)[0] === 200 && acHttp('/auth/me', $client)[0] === 200, 'unrelated admin and customer sessions remain valid');
acCheck(acFacts() === $facts, 'account operations leave customers money invoices resources and tasks untouched');
foreach ($original as $a) {
    $db->prepare('UPDATE admins SET status=? WHERE id=?')->execute([$a['status'], $a['id']]);
}
$db->prepare("UPDATE admins SET role='super_admin',status='active' WHERE id=?")->execute([$aid]);
echo "Admin account checks: {$checks}\n";
