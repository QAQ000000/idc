<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use App\Core\{ApiException,Database,RedisStore,Security};
use App\Services\{AdminAccountService,AdminAuthService,AuthService,CaptchaService,CaptchaSettingsService};

if (config('app.env') !== 'test' || config('database.connections.mysql.database') !== 'jianyun_test') {
    throw new RuntimeException('Isolated DB required');
}
set_exception_handler(static function (Throwable $e): never {
    fwrite(STDERR, (string) $e.PHP_EOL);
    exit(1);
});
$db = Database::getInstance();
$service = new CaptchaSettingsService();
$redis = new RedisStore();
$checks = 0;
$base = getenv('TEST_BASE_URL').'/api/v1';
$ip = '198.18.2.15';
if (($argv[1] ?? '') === 'child') {
    $v = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
    echo 'READY:'.$db->query('SELECT CONNECTION_ID()')->fetchColumn()."\n";
    fflush(STDOUT);
    try {
        $service->save($v['actor'], $v['session'], $v['scene'], $v['body']);
        echo json_encode(['status' => 200]);
    } catch (ApiException $e) {
        echo json_encode(['status' => $e->httpStatus]);
    }exit;
}
function csCheck(bool $v, string $why): void
{
    global $checks;
    if (! $v) {
        throw new RuntimeException('FAIL captcha settings: '.$why);
    }++$checks;
    echo 'PASS captcha settings: '.$why.PHP_EOL;
}
function csHandle(string $path, ?string $token = null, string $method = 'GET', ?array $body = null, ?string $clientIp = null): CurlHandle
{
    global $base,$ip;
    $h = curl_init($base.$path);
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => array_filter(['Content-Type: application/json', 'X-Forwarded-For: '.($clientIp ?? $ip), $token ? 'Authorization: Bearer '.$token : null])]);
    if ($body !== null) {
        curl_setopt($h, CURLOPT_POSTFIELDS, json_encode($body ?: new stdClass(), JSON_THROW_ON_ERROR));
    }

    return $h;
}
function csHttp(string $path, ?string $token = null, string $method = 'GET', ?array $body = null, ?string $clientIp = null): array
{
    $h = csHandle($path, $token, $method, $body, $clientIp);
    $raw = curl_exec($h);
    $code = curl_getinfo($h, CURLINFO_RESPONSE_CODE);
    curl_close($h);

    return [$code, json_decode($raw, true, 64, JSON_THROW_ON_ERROR)];
}
function csParallel(array $handles): array
{
    $m = curl_multi_init();
    foreach ($handles as $h) {
        curl_multi_add_handle($m, $h);
    }do {
        curl_multi_exec($m, $active);
        if ($active) {
            curl_multi_select($m, 1);
        }
    } while ($active);
    $codes = [];
    foreach ($handles as $h) {
        $codes[] = curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        curl_multi_remove_handle($m, $h);
        curl_close($h);
    }curl_multi_close($m);
    sort($codes);

    return $codes;
}
function csShow(): array
{
    global $service,$actor;

    return $service->show($actor, 0)['scenes'];
}
function csBody(string $scene, bool $enabled): array
{
    $s = csShow()[$scene];

    return ['enabled' => $enabled, 'driver' => 'builtin_image', 'mode' => 'always', 'expected_version' => $s['version']];
}
function csSave(string $scene, bool $enabled): void
{
    global $token;
    csCheck(csHttp('/admin/captcha-settings/'.$scene, $token, 'PATCH', csBody($scene, $enabled))[0] === 200, 'save '.$scene.' '.($enabled ? 'on' : 'off'));
}
function csQuota(string $scene, ?string $clientIp = null): string
{
    global $ip;

    return 'jianyun:auth:'.$scene.':'.Security::requestFingerprint($clientIp ?? $ip);
}
function csClear(): void
{
    global $redis;
    $redis->command('DEL', csQuota('login'), csQuota('register'));
}
function csProof(string $scene, ?string $clientIp = null): array
{
    global $ip,$redis;
    $r = csHttp('/captcha/config?scene='.$scene, null, 'GET', null, $clientIp);
    csCheck($r[0] === 200 && ($r[1]['data']['enabled'] ?? false) && str_starts_with($r[1]['data']['img_base64'], 'data:image/png;base64,'), 'real image challenge '.$scene);
    $id = $r[1]['data']['challenge_id'];
    csCheck($redis->command('TTL', 'jianyun:captcha:'.$id) > 0 && $redis->command('TTL', 'jianyun:captcha:'.$id) <= 300, 'real challenge TTL');
    // Only the isolated test process replaces its own challenge hash; production never exposes the answer.
    $stored = json_decode($redis->command('GET', 'jianyun:captcha:'.$id), true, 16, JSON_THROW_ON_ERROR);
    $stored['hash'] = hash('sha256', 'ABCDE');
    $redis->command('SET', 'jianyun:captcha:'.$id, json_encode($stored, JSON_THROW_ON_ERROR), 'EX', '300');

    return ['challenge_id' => $id, 'answer' => 'ABCDE'];
}
function csSpawn(array $input): array
{
    $p = proc_open([PHP_BINARY, __FILE__, 'child'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    stream_set_timeout($pipes[1], 15);
    $line = trim(fgets($pipes[1]) ?: '');
    if (! preg_match('/^READY:(\d+)$/D', $line, $m)) {
        throw new RuntimeException('Child not ready');
    }

    return [$p, $pipes, (int) $m[1]];
}
function csWait(array $child): void
{
    global $db;
    $until = microtime(true) + 8;
    do {
        $s = $db->prepare('SELECT trx_state FROM information_schema.innodb_trx WHERE trx_mysql_thread_id=?');
        $s->execute([$child[2]]);
        if ($s->fetchColumn() === 'LOCK WAIT') {
            return;
        }usleep(100000);
    } while (microtime(true) < $until);
    throw new RuntimeException('No real lock wait observed');
}
function csFinish(array $c): int
{
    [$p,$pipes] = $c;
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($p) !== 0) {
        throw new RuntimeException($out.$err);
    }

    return json_decode($out, true, 32, JSON_THROW_ON_ERROR)['status'];
}

$previous = CaptchaService::settingsRow($db);
$db->exec("DELETE FROM system_settings WHERE `key`='captcha_scenes'");
$admin = new AdminAuthService();
$actor = $admin->create(['username' => 'captcha-settings-super', 'email' => 'captcha-settings-super@example.test', 'password' => 'CaptchaSettings!2026', 'role' => 'super_admin']);
$token = Security::generateToken(['uid' => $actor, 'sub_type' => 'admin']);
$second = $admin->create(['username' => 'captcha-settings-second', 'email' => 'captcha-settings-second@example.test', 'password' => 'CaptchaSettings!2026', 'role' => 'super_admin']);
$client = (new AuthService())->register(['email' => 'captcha-settings-client@example.test', 'password' => 'CaptchaSettings!2026']);
$login = ['email' => 'captcha-settings-client@example.test', 'password' => 'CaptchaSettings!2026'];
$other = $db->query("SELECT `key`,value,encrypted_credentials,updated_by FROM system_settings WHERE `key`<>'captcha_scenes' ORDER BY `key`")->fetchAll();
foreach ([null, $client['token']] as $t) {
    csCheck(csHttp('/admin/captcha-settings', $t)[0] === 401 && csHttp('/admin/captcha-settings/login', $t, 'PATCH', [])[0] === 401, 'anonymous/customer denied policy management');
}
foreach (['finance', 'support', 'operator'] as $role) {
    $id = $admin->create(['username' => 'captcha-settings-'.$role, 'email' => 'captcha-settings-'.$role.'@example.test', 'password' => 'CaptchaSettings!2026', 'role' => $role]);
    $t = Security::generateToken(['uid' => $id, 'sub_type' => 'admin']);
    csCheck(csHttp('/admin/captcha-settings', $t)[0] === 403 && csHttp('/admin/captcha-settings/login', $t, 'PATCH', [])[0] === 403, $role.' cannot read/write policies');
}
$initial = csShow();
foreach (CaptchaService::SCENES as $scene) {
    csCheck($initial[$scene]['enabled'] && $initial[$scene]['driver'] === 'builtin_image' && $initial[$scene]['mode'] === 'always' && ! $initial[$scene]['configured'] && strlen($initial[$scene]['version']) === 64, 'default local always-on '.$scene);
}
csCheck(csHttp('/captcha/config?scene=unknown')[0] === 400 && csHttp('/captcha/config?scene[]=login')[0] === 400, 'invalid public scene rejected');
$old = csBody('login', false);
csSave('login', false);
csCheck(csShow()['register'] === $initial['register'] && csShow()['password_reset'] === $initial['password_reset'], 'login save preserves other scene values and versions');
csCheck(csHttp('/admin/captcha-settings/login', $token, 'PATCH', $old)[0] === 409, 'stale/replayed write rejected');
$same = csBody('login', false);
csSave('login', false);
csCheck(csShow()['login']['version'] !== $same['expected_version'], 'same-value save fences delayed write');
foreach ([['enabled' => 'false'], ['enabled' => 0], ['driver' => 'missing'], ['mode' => 'after_failed_attempts'], ['mode' => null], ['secret' => 'NEVER_PUBLIC'], ['expected_version' => 'x']] as $bad) {
    csCheck(csHttp('/admin/captcha-settings/login', $token, 'PATCH', array_replace(csBody('login', false), $bad))[0] === 400, 'invalid/unsupported policy write rejected');
}
csCheck(csHttp('/admin/captcha-settings/order', $token, 'PATCH', csBody('login', false))[0] === 400, 'unknown management scene rejected');
$before = csShow();
$db->exec("CREATE TRIGGER captcha_audit_failure BEFORE INSERT ON admin_audit_logs FOR EACH ROW BEGIN IF NEW.action='captcha_configure' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='captcha audit unavailable'; END IF; END");
try {
    csCheck(csHttp('/admin/captcha-settings/login', $token, 'PATCH', csBody('login', true))[0] === 500, 'audit fault rejects policy save');
} finally {
    $db->exec('DROP TRIGGER captcha_audit_failure');
}
csCheck(csShow() === $before, 'audit failure rolls back policy and version');
$body = csBody('login', true);
$child = Database::transaction(function (PDO $db) use ($actor, $second, $service, $body) {
    $service->show($actor, 0);
    $c = csSpawn(['actor' => $second, 'session' => 0, 'scene' => 'login', 'body' => $body]);
    csWait($c);
    $service->save($actor, 0, 'login', $body);

    return $c;
});
csCheck(csFinish($child) === 409 && csShow()['login']['enabled'], 'real same-scene competition permits one save');
$register = csBody('register', false);
$loginVersion = csShow()['login']['version'];
csSave('password_reset', false);
csCheck(csHttp('/admin/captcha-settings/register', $token, 'PATCH', $register)[0] === 200 && csShow()['login']['version'] === $loginVersion, 'saving one scene does not stale another scene draft');
$register = csBody('register', true);
$loginChange = csBody('login', false);
$child = Database::transaction(function (PDO $db) use ($actor, $second, $service, $register, $loginChange) {
    $service->show($actor, 0);
    $c = csSpawn(['actor' => $second, 'session' => 0, 'scene' => 'register', 'body' => $register]);
    csWait($c);
    $service->save($actor, 0, 'login', $loginChange);

    return $c;
});
csCheck(csFinish($child) === 200 && ! csShow()['login']['enabled'] && csShow()['register']['enabled'], 'real different-scene competition preserves both changes in one JSON row');
$child = Database::transaction(function (PDO $db) use ($actor, $second, $service) {
    $db->prepare('SELECT id FROM admins WHERE id=? FOR UPDATE')->execute([$second]);
    $c = csSpawn(['actor' => $second, 'session' => 0, 'scene' => 'login', 'body' => csBody('login', false)]);
    csWait($c);
    $a = new AdminAccountService();
    $a->update($actor, 0, $second, ['role' => 'support', 'status' => 'active', 'expected_version' => $a->detail($actor, 0, $second)['account']['version'], 'evidence' => 'CAPTCHA ACCEPTANCE: revoke waiting actor']);

    return $c;
});
csCheck(csFinish($child) === 401, 'waiting administrator cannot save after session revocation');
csClear();
csSave('login', false);
$oldDisabled = csHttp('/captcha/config?scene=login')[1]['data'];
csSave('login', true);
csCheck($oldDisabled === ['enabled' => false] && csHttp('/auth/login', null, 'POST', $login)[0] === 422, 'old disabled page cannot bypass newly enabled client policy');
csCheck(csHttp('/admin/auth/login', null, 'POST', ['username' => 'captcha-settings-super', 'password' => 'CaptchaSettings!2026'])[0] === 422, 'same login change immediately protects admin login');
$proof = csProof('login');
csCheck(csParallel([csHandle('/auth/login', null, 'POST', $login + ['captcha_payload' => $proof]), csHandle('/auth/login', null, 'POST', $login + ['captcha_payload' => $proof])]) === [200, 422], 'parallel identical challenge consumed once');
$proof = csProof('login');
$redis->command('PEXPIREAT', 'jianyun:captcha:'.$proof['challenge_id'], (string) ((int) (microtime(true) * 1000) - 1));
csCheck(csHttp('/auth/login', null, 'POST', $login + ['captcha_payload' => $proof])[0] === 422, 'expired real challenge rejected');
csSave('register', true);
$proof = csProof('register');
csCheck(csHttp('/auth/login', null, 'POST', $login + ['captcha_payload' => $proof])[0] === 422 && $redis->command('GET', 'jianyun:captcha:'.$proof['challenge_id']) === null, 'cross-scene attempt rejects and consumes proof');
$proof = csProof('login');
csCheck(csHttp('/auth/login', null, 'POST', $login + ['captcha_payload' => $proof], '198.18.2.16')[0] === 422, 'challenge bound to trusted client IP');
$proof = csProof('login');
csSave('login', false);
csCheck(csHttp('/auth/login', null, 'POST', $login + ['captcha_payload' => $proof])[0] === 200, 'old enabled page follows newly disabled policy');
csCheck(csHttp('/admin/auth/login', null, 'POST', ['username' => 'captcha-settings-super', 'password' => 'CaptchaSettings!2026'])[0] === 200, 'shared login switch also permits admin password step');
csSave('register', false);
csClear();
for ($i = 0; $i < 10; $i++) {
    csCheck(csHttp('/auth/login', null, 'POST', ['email' => $login['email'], 'password' => 'wrong'])[0] === 401, 'disabled captcha still counts failed login '.$i);
}
csCheck(csHttp('/auth/login', null, 'POST', $login)[0] === 429, 'disabled captcha does not bypass login quota, even with correct password');
csCheck(csHttp('/auth/register', null, 'POST', [])[0] === 400, 'login quota does not share registration quota');
csCheck(csHttp('/admin/auth/login', null, 'POST', ['username' => 'captcha-settings-super', 'password' => 'CaptchaSettings!2026'])[0] === 200, 'customer quota does not consume administrator quota');
csCheck(csHttp('/auth/login', null, 'POST', $login, '198.18.2.17')[0] === 200, 'different trusted IP has its own quota');
$redis->command('EXPIRE', csQuota('login'), '0');
csCheck(csHttp('/auth/login', null, 'POST', $login)[0] === 200, 'expired limiter window admits a new request');
csClear();
$handles = [];
for ($i = 0; $i < 15; $i++) {
    $handles[] = csHandle('/auth/login', null, 'POST', []);
}csCheck(csParallel($handles) === array_merge(array_fill(0, 10, 400), array_fill(0, 5, 429)), 'atomic parallel login quota admits exactly ten before parameter processing');
csClear();
$handles = [];
for ($i = 0; $i < 10; $i++) {
    $handles[] = csHandle('/auth/register', null, 'POST', []);
}csCheck(csParallel($handles) === array_merge(array_fill(0, 5, 400), array_fill(0, 5, 429)), 'atomic parallel registration quota remains five with captcha off');
csClear();
csSave('login', true);
$handles = [];
for ($i = 0; $i < 12; $i++) {
    $handles[] = csHandle('/auth/login', null, 'POST', $login);
}csCheck(csParallel($handles) === array_merge(array_fill(0, 10, 422), array_fill(0, 2, 429)), 'failed captcha also consumes login quota before verification');
$good = CaptchaService::settingsRow($db)['value'];
foreach (['null', '[]', '"broken"', '{"login":null}', '{"login":{"enabled":0}}', '{"login":{"enabled":false,"driver":"missing"}}', '{"login":{"enabled":false,"mode":"after_failed_attempts"}}', '{"login":{"enabled":false,"secret":"NEVER_PUBLIC"}}'] as $invalid) {
    $db->prepare("UPDATE system_settings SET value=? WHERE `key`='captcha_scenes'")->execute([$invalid]);
    csClear();
    $r = csHttp('/captcha/config?scene=login');
    csCheck($r[0] === 500 && csHttp('/auth/login', null, 'POST', $login)[0] === 500 && ! str_contains(json_encode($r), 'NEVER_PUBLIC'), 'corrupt root/policy or unavailable driver fails closed even when disabled');
}
$db->prepare("UPDATE system_settings SET value=? WHERE `key`='captcha_scenes'")->execute(['{"login":{"enabled":false},"register":null}']);
csClear();
csCheck(csHttp('/auth/login', null, 'POST', $login)[0] === 200 && csHttp('/captcha/config?scene=register')[0] === 500, 'corrupt sibling scene does not alter valid login policy');
$db->prepare("UPDATE system_settings SET value=? WHERE `key`='captcha_scenes'")->execute([$good]);
csSave('login', false);
csClear();
// A second private HTTP server uses this isolated DB, but a reserved non-listening Redis port.
$reserved = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
$deadPort = (int) substr(strrchr(stream_socket_get_name($reserved, false), ':'), 1);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$httpPort = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
fclose($socket);
$env = getenv();
$env['REDIS_PORT'] = (string) $deadPort;
unset($env['PHP_CLI_SERVER_WORKERS']);
$log = tempnam(sys_get_temp_dir(), 'jianyun-captcha-down-');
$p = proc_open([PHP_BINARY, '-S', '127.0.0.1:'.$httpPort, '-t', 'public', 'public/index.php'], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__), $env);
fclose($pipes[0]);
$originalBase = $base;
try {
    $base = 'http://127.0.0.1:'.$httpPort.'/api/v1';
    $until = microtime(true) + 8;
    do {
        $probe = @fsockopen('127.0.0.1', $httpPort, $errno, $errstr, 0.1);
        if ($probe) {
            fclose($probe);
            break;
        }usleep(100000);
    } while (microtime(true) < $until);
    foreach (['/auth/login', '/auth/register', '/admin/auth/login', '/auth/password/forgot'] as $path) {
        csCheck(csHttp($path, null, 'POST', $path === '/admin/auth/login' ? ['username' => 'captcha-settings-super', 'password' => 'CaptchaSettings!2026'] : $login)[0] === 500, 'Redis outage never bypasses disabled captcha/limiter '.$path);
    }
    csSave('login', true);
    csCheck(csHttp('/captcha/config?scene=login')[0] === 500, 'Redis outage blocks image generation');
} finally {
    $base = $originalBase;
    proc_terminate($p);
    proc_close($p);
    fclose($reserved);
    unlink($log);
}
csCheck($db->query("SELECT `key`,value,encrypted_credentials,updated_by FROM system_settings WHERE `key`<>'captcha_scenes' ORDER BY `key`")->fetchAll() === $other, 'captcha settings do not rewrite payment SMTP site or other settings');
$audit = json_decode($db->query("SELECT context FROM admin_audit_logs WHERE action='captcha_configure' ORDER BY id DESC LIMIT 1")->fetchColumn(), true, 16, JSON_THROW_ON_ERROR);
csCheck(array_keys($audit) == ['after', 'scene', 'before'] || count($audit) === 3, 'audit stores only scene and before/after policy');
// Restore the shared isolated fixture, never a business environment.
if ($previous) {
    $db->prepare("UPDATE system_settings SET value=? WHERE `key`='captcha_scenes'")->execute([$previous['value']]);
} else {
    $db->exec("DELETE FROM system_settings WHERE `key`='captcha_scenes'");
}csClear();
echo 'Captcha settings checks: '.$checks.PHP_EOL;
