<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use App\Core\{ApiException,Database,Security};
use App\Services\{AdminAuthService,AdminProviderService,AuthService,BillingService,DemoService,ProviderConnectionService};

if (config('app.env') !== 'test' || config('database.connections.mysql.database') !== 'jianyun_test') {
    throw new RuntimeException('Isolated database required');
}
$db = Database::getInstance();
$checks = 0;
function pcCheck(bool $ok, string $label): void
{
    global $checks;
    if (! $ok) {
        throw new RuntimeException('FAIL: '.$label);
    }$checks++;
    echo 'PASS: '.$label.PHP_EOL;
}
function pcHttp(string $path, ?string $token = null, string $method = 'GET', ?array $body = null): array
{
    $h = curl_init(getenv('TEST_BASE_URL').'/api/v1'.$path);
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer '.$token;
    }$cache = '';
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 45, CURLOPT_HEADERFUNCTION => static function ($h, $line) use (&$cache) {
        if (str_starts_with(strtolower($line), 'cache-control:')) {
            $cache = trim(substr($line, 14));
        }

        return strlen($line);
    }]);
    if ($body !== null) {
        curl_setopt($h, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    }
    $raw = curl_exec($h);
    $code = curl_getinfo($h, CURLINFO_RESPONSE_CODE);
    curl_close($h);

    return [$code, json_decode($raw, true, 32, JSON_THROW_ON_ERROR), $cache];
}
function pcGet(string $path, string $token): array
{
    return pcHttp($path, $token)[1]['data'];
}
function pcState(): array
{
    return json_decode(file_get_contents(getenv('UPSTREAM_TEST_URL').'/__state'), true, 32, JSON_THROW_ON_ERROR);
}
function pcFacts(): string
{
    global $db;
    $data = [];
    foreach (['users', 'instances', 'invoices', 'transactions', 'payment_records', 'tasks', 'products', 'system_settings'] as $t) {
        $data[$t] = $db->query('SELECT * FROM '.$t.' ORDER BY 1')->fetchAll();
    }

    return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
}
$tokens = [];
$ids = [];
$auth = new AdminAuthService();
foreach (['super_admin', 'finance', 'support', 'operator'] as $role) {
    $ids[$role] = $auth->create(['username' => 'provider-'.$role, 'email' => 'provider-'.$role.'@example.test', 'password' => 'ProviderAdmin!2026', 'role' => $role]);
    $tokens[$role] = Security::generateToken(['uid' => $ids[$role], 'sub_type' => 'admin']);
}
$client = (new AuthService())->register(['email' => 'provider-client@example.test', 'password' => 'ProviderClient!2026']);
$admin = $tokens['super_admin'];
$root = '/admin/provider-connections';
foreach ([$root, '/admin/provider-drivers'] as $path) {
    pcCheck(pcHttp($path)[0] === 401 && pcHttp($path, $client['token'])[0] === 401, 'anonymous/customer reject '.$path);
}
$catalog = pcGet('/admin/provider-drivers', $admin);
pcCheck(array_column($catalog['items'], 'id') === ['manual', 'zjmf'], 'only installed configurable driver catalog');
$body = ['name' => 'fixture%_!provider', 'driver' => 'zjmf', 'endpoint' => getenv('UPSTREAM_TEST_URL'), 'credentials' => ['username' => 'probe-private-account', 'password' => 'PROVIDER_SECRET_DO_NOT_RETURN'], 'request_key' => 'provider-create-key-0001', 'catalog_version' => $catalog['version'], 'evidence' => 'PROVIDER-TEST: local fixture connection'];
foreach (['finance', 'support', 'operator'] as $role) {
    $t = $tokens[$role];
    pcCheck(pcHttp($root, $t)[0] === 200, 'read listing allowed '.$role);
    pcCheck(pcHttp('/admin/provider-drivers', $t)[0] === 403 && pcHttp($root, $t, 'POST', $body)[0] === 403, 'write/catalog denied '.$role);
}
$baseline = pcFacts();
$upstream = pcState();
[$code,$response,$cache] = pcHttp($root, $admin, 'POST', $body);
$id = $response['data']['provider_id'];
pcCheck($code === 200 && str_contains($cache, 'no-store'), 'create encrypted connection without network/business writes');
pcCheck(pcFacts() === $baseline && pcState() === $upstream, 'creation does not mutate payment/product/resource facts or call upstream');
$s = $db->prepare('SELECT * FROM providers WHERE id=?');
$s->execute([$id]);
$raw = $s->fetch();
$encrypted = json_decode($raw['credentials'], true);
pcCheck(! str_contains($raw['credentials'], 'PROVIDER_SECRET') && Security::decrypt($encrypted['password']) === $body['credentials']['password'] && Security::decrypt($encrypted['username']) === $body['credentials']['username'], 'all account credential values AES-GCM encrypted');
pcCheck(pcHttp($root, $admin, 'POST', $body)[1]['data']['provider_id'] === $id, 'same request replays original connection');
$changed = $body;
$changed['credentials']['password'] = 'DifferentPrivateSecret';
pcCheck(pcHttp($root, $admin, 'POST', $changed)[0] === 409, 'same request cannot change account');
foreach (['ftp://example.test', 'https://user:secret@example.test', 'https://example.test?secret=x', 'https://example.test#secret', 'file:///tmp/secret'] as $endpoint) {
    $bad = $body;
    $bad['request_key'] .= 'bad';
    $bad['endpoint'] = $endpoint;
    pcCheck(pcHttp($root, $admin, 'POST', $bad)[0] === 400, 'reject unsafe base URL');
}
foreach ([['driver' => 'missing'], ['credentials' => ['username' => 'x', 'password' => '']], ['credentials' => ['username' => [], 'password' => 'x']], ['credentials' => ['username' => 'x', 'password' => 'x', 'extra' => 'secret']], ['request_key' => 'short'], ['status' => 0], ['evidence' => 'short']] as $change) {
    pcCheck(pcHttp($root, $admin, 'POST', array_replace($body, $change))[0] === 400, 'invalid connection input rejected');
}
$bad = $body;
$bad['request_key'] .= 'version';
$bad['catalog_version'] = str_repeat('0', 64);
pcCheck(pcHttp($root, $admin, 'POST', $bad)[0] === 409, 'stale driver form rejected');
$detail = pcGet($root.'/'.$id, $admin);
pcCheck($detail['configuration_complete'] && $detail['driver_installed'] && $detail['snapshot_supported'] && $detail['product_count'] === 0 && $detail['instance_count'] === 0, 'new connection configuration flags and empty counts');
foreach ($tokens as $role => $token) {
    [$code,$r,$cache] = pcHttp($root.'/'.$id, $token);
    $encoded = json_encode($r);
    pcCheck($code === 200 && str_contains($cache, 'no-store') && ! str_contains($encoded, 'PROVIDER_SECRET') && ! str_contains($encoded, 'probe-private') && ! str_contains($encoded, getenv('UPSTREAM_TEST_URL')) && ! isset($r['data']['credentials'],$r['data']['endpoint']), 'safe detail/audit projection '.$role);
}
$r = pcGet($root.'?name='.urlencode('%_!'), $admin);
pcCheck($r['total'] === 1 && $r['items'][0]['id'] === $id, 'literal provider name filtering');
pcCheck(pcGet($root.'?driver=zjmf&status=0&name='.urlencode($body['name']), $admin)['total'] === 0, 'combined driver/status filtering');
pcCheck(pcGet($root.'?name=no-such-provider', $admin)['items'] === [], 'empty provider list');
$p1 = pcGet($root.'?per_page=1', $admin);
$p2 = pcGet($root.'?per_page=1&page=2', $admin);
pcCheck(count($p1['items']) === 1 && $p1['items'][0]['id'] > $p2['items'][0]['id'], 'descending paginated connections');
foreach (['page=0', 'per_page=101', 'page[]=1', 'name[]=x', 'driver[]=x', 'status[]=1', 'status=2'] as $q) {
    pcCheck(pcHttp($root.'?'.$q, $admin)[0] === 400, 'invalid provider filter '.$q);
}
pcCheck(pcHttp($root.'/999999999', $admin)[0] === 404 && pcHttp($root.'/'.$id.'?audit_page=0', $admin)[0] === 400, 'missing provider/invalid audit pagination');
$rename = ['name' => 'renamed-connection', 'expected_version' => $detail['version'], 'evidence' => 'PROVIDER-TEST: display name only'];
foreach (['finance', 'support', 'operator'] as $role) {
    pcCheck(pcHttp($root.'/'.$id, $tokens[$role], 'PATCH', $rename)[0] === 403 && pcHttp($root.'/'.$id.'/verify', $tokens[$role], 'POST', ['remote_pid' => 391, 'billing_cycle' => 'monthly', 'expected_version' => $detail['version']])[0] === 403, 'rename/verification denied '.$role);
}
foreach (['endpoint' => 'https://other.example.test', 'driver' => 'manual', 'credentials' => ['username' => 'other', 'password' => 'secret'], 'status' => 0, 'id' => 999] as $k => $v) {
    pcCheck(pcHttp($root.'/'.$id, $admin, 'PATCH', $rename + [$k => $v])[0] === 400, 'immutable namespace rejects '.$k);
}
pcCheck(pcHttp($root.'/'.$id, $admin, 'PATCH', $rename)[0] === 200, 'display rename accepted with audit');
pcCheck(pcHttp($root.'/'.$id, $admin, 'PATCH', $rename)[0] === 409, 'old display version rejected');
$detail = pcGet($root.'/'.$id, $admin);
pcCheck($detail['name'] === 'renamed-connection' && $detail['audit']['total'] === 2, 'create/rename audit visible');
$rename['name'] = $body['name'];
$rename['expected_version'] = $detail['version'];
pcCheck(pcHttp($root.'/'.$id, $admin, 'PATCH', $rename)[0] === 200 && pcGet($root.'/'.$id, $admin)['version'] !== $rename['expected_version'], 'rename back still advances version');
$detail = pcGet($root.'/'.$id, $admin);
$version = $detail['version'];
$verify = ['remote_pid' => 391, 'billing_cycle' => 'monthly', 'expected_version' => $version];
$baseline = pcFacts();
$upstream = pcState();
[$code,$result] = pcHttp($root.'/'.$id.'/verify', $admin, 'POST', $verify);
pcCheck($code === 200 && $result['data']['first_price'] === '0.01' && $result['data']['field_count'] === 0, 'fixed product read-only quote verification');
pcCheck(pcFacts() === $baseline && pcState() === $upstream, 'verification leaves all money/resource/order facts and upstream operations unchanged');
pcCheck(pcGet($root.'/'.$id, $admin)['version'] === $version, 'verification audit does not invalidate connection version');
$verify['remote_pid'] = 14;
$result = pcHttp($root.'/'.$id.'/verify', $admin, 'POST', $verify);
pcCheck($result[0] === 200 && $result[1]['data']['field_count'] > 0 && $result[1]['data']['first_price'] === '16.00', 'configurable product default quote verification');
foreach ([['remote_pid' => []], ['remote_pid' => 0], ['billing_cycle' => 'weekly'], ['path' => '/cart/settle'], ['credentials' => ['password' => 'override']], ['expected_version' => str_repeat('0', 64)]] as $change) {
    pcCheck(in_array(pcHttp($root.'/'.$id.'/verify', $admin, 'POST', array_replace($verify, $change))[0], [400, 409], true), 'verification rejects arbitrary/invalid input');
}
file_get_contents(getenv('UPSTREAM_TEST_URL').'/__config_mode?mode=unavailable');
$failed = pcHttp($root.'/'.$id.'/verify', $admin, 'POST', $verify);
file_get_contents(getenv('UPSTREAM_TEST_URL').'/__config_mode?mode=normal');
pcCheck($failed[0] === 409 && ! str_contains(json_encode($failed), $body['credentials']['password']), 'verification failure redacted');
$latest = pcGet($root.'/'.$id, $admin);
pcCheck($latest['audit']['items'][0]['context']['outcome'] === 'unavailable' && $latest['status'] === 1, 'failure audited without disabling original connection');
$import = ['provider_id' => $id, 'remote_pid' => 391, 'name' => 'provider-import-test', 'type' => 'cdn', 'markup_type' => 'none', 'markup_value' => '0.00', 'billing_cycles' => ['monthly']];
$preview = pcHttp('/admin/products/preview', $admin, 'POST', $import);
pcCheck($preview[0] === 200, 'new connection uses existing product preview');
$import['preview_token'] = $preview[1]['data']['preview_token'];
$import['evidence'] = 'PROVIDER-TEST: import reviewed quote';
$saved = pcHttp('/admin/products/import', $admin, 'POST', $import);
$product = $saved[1]['data']['product_id'];
$p = pcGet('/admin/products/'.$product, $admin);
pcCheck($saved[0] === 200 && $p['status'] === 'hidden' && $p['provider_id'] === $id, 'existing import binds new provider and defaults hidden');
$customer = $client['user']['id'];
(new DemoService())->recharge($customer, '1.00', 'provider-fixture');
$db->prepare("UPDATE products SET status='active' WHERE id=?")->execute([$product]);
$invoice = (new BillingService())->checkout($customer, ['product_id' => $product, 'hostname' => 'Provider8', 'password' => 'ProviderRoot!2026', 'billing_cycle' => 'monthly', 'use_balance' => true]);
$detail = pcGet($root.'/'.$id, $admin);
pcCheck($detail['product_count'] === 1 && $detail['instance_count'] === 1, 'connection counts use original bindings');
$instances = pcGet('/admin/instances?provider_id='.$id, $tokens['support']);
pcCheck($instances['total'] === 1 && $instances['items'][0]['provider_id'] === $id, 'provider instance navigation scoped to original connection');
pcCheck(pcHttp('/admin/instances?provider_id[]=1', $admin)[0] === 400, 'provider instance scope validated');
$baseline = pcFacts();
$rename['name'] = 'final-visible-name';
$rename['expected_version'] = $detail['version'];
pcCheck(pcHttp($root.'/'.$id, $admin, 'PATCH', $rename)[0] === 200 && pcFacts() === $baseline, 'metadata change preserves all historical financial/resource bindings');
$manual = $body;
$manual['driver'] = 'manual';
unset($manual['endpoint']);
$manual['credentials'] = [];
$manual['request_key'] .= 'manual';
$m = pcHttp($root, $admin, 'POST', $manual);
$mid = $m[1]['data']['provider_id'];
$md = pcGet($root.'/'.$mid, $admin);
pcCheck($m[0] === 200 && $md['configuration_complete'] && ! $md['snapshot_supported'], 'manual installed connection requires no fictitious credentials');
pcCheck(pcHttp($root.'/'.$mid.'/verify', $admin, 'POST', ['remote_pid' => 391, 'billing_cycle' => 'monthly', 'expected_version' => $md['version']])[0] === 409, 'manual driver does not pretend online validation succeeded');
$before = (int) $db->query('SELECT COUNT(*) FROM providers')->fetchColumn();
$db->exec("CREATE TRIGGER provider_audit_failure BEFORE INSERT ON admin_audit_logs FOR EACH ROW BEGIN IF NEW.action='provider_create' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='TEST'; END IF; END");
$rollback = $body;
$rollback['request_key'] .= 'rollback';
$r = pcHttp($root, $admin, 'POST', $rollback);
$db->exec('DROP TRIGGER provider_audit_failure');
pcCheck($r[0] === 500 && (int) $db->query('SELECT COUNT(*) FROM providers')->fetchColumn() === $before, 'audit failure rolls back connection creation');
// Concurrent retries share one committed namespace and audit, even if the first response is lost.
$concurrent = $body;
$concurrent['request_key'] .= 'concurrent';
$multi = curl_multi_init();
$handles = [];
for ($n = 0; $n < 6; $n++) {
    $h = curl_init(getenv('TEST_BASE_URL').'/api/v1'.$root);
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer '.$admin], CURLOPT_POSTFIELDS => json_encode($concurrent), CURLOPT_TIMEOUT => 30]);
    $handles[] = $h;
    curl_multi_add_handle($multi, $h);
}
do {
    curl_multi_exec($multi, $running);
    if ($running) {
        curl_multi_select($multi, 0.2);
    }
} while ($running);
$results = [];
foreach ($handles as $h) {
    $r = json_decode(curl_multi_getcontent($h), true);
    $results[] = [curl_getinfo($h, CURLINFO_RESPONSE_CODE), $r['data']['provider_id'] ?? null];
    curl_multi_remove_handle($multi, $h);
    curl_close($h);
}curl_multi_close($multi);
pcCheck(count(array_filter($results, fn ($r) => $r[0] === 200)) === 6 && count(array_unique(array_column($results, 1))) === 1, 'six simultaneous retries create one connection');
$s = $db->prepare("SELECT COUNT(*) FROM admin_audit_logs WHERE target_type='provider' AND target_id=? AND action='provider_create'");
$s->execute([$results[0][1]]);
pcCheck((int) $s->fetchColumn() === 1, 'concurrent create has one atomic audit');
// Disabled connections and corrupt/missing credentials fail safely without lifecycle calls.
$db->prepare('UPDATE providers SET status=0 WHERE id=?')->execute([$id]);
$disabled = pcGet($root.'/'.$id, $admin);
pcCheck(pcHttp($root.'/'.$id.'/verify', $admin, 'POST', ['remote_pid' => 391, 'billing_cycle' => 'monthly', 'expected_version' => $disabled['version']])[0] === 409, 'disabled provider cannot validate remotely');
$db->prepare('UPDATE providers SET status=1 WHERE id=?')->execute([$id]);
$db->prepare("UPDATE admins SET role='support' WHERE id=?")->execute([$ids['super_admin']]);
pcCheck(pcHttp($root, $admin)[0] === 200 && pcHttp('/admin/provider-drivers', $admin)[0] === 403 && pcHttp($root, $admin, 'POST', $body)[0] === 403, 'existing token downgrade effective for configuration writes');
$db->prepare("UPDATE admins SET status='disabled' WHERE id=?")->execute([$ids['support']]);
pcCheck(pcHttp($root.'/'.$id, $tokens['support'])[0] === 403, 'disabled reader rejected');
try {
    (new AdminProviderService())->listing($ids['support'], []);
    pcCheck(false, 'service identity check');
} catch (ApiException $e) {
    pcCheck($e->httpStatus === 403, 'direct service rechecks active identity');
}
pcCheck(is_array(pcGet('/admin/providers', $tokens['finance'])) && array_is_list(pcGet('/admin/providers', $tokens['finance'])), 'legacy provider selector response remains array');
echo 'Admin provider checks: '.$checks.PHP_EOL;
