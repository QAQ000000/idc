<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use App\Core\{ApiException, Database, Security};
use App\Plugins\Providers\UpstreamCatalogItem;
use App\Services\{AdminAuthService, AdminProductService, ProviderService, UpstreamCatalogService};
use Illuminate\Support\Facades\Cache;

if (config('app.env') !== 'test' || config('database.connections.mysql.database') !== 'jianyun_test') {
    throw new RuntimeException('Isolated database required');
}
$db = Database::getInstance();
$checks = 0;
function catalogCheck(bool $ok, string $label): void
{
    global $checks;
    if (! $ok) {
        throw new RuntimeException('FAIL CATALOG: '.$label);
    }
    $checks++;
    echo 'PASS CATALOG: '.$label.PHP_EOL;
}
function catalogMode(string $mode): void
{
    file_get_contents(getenv('UPSTREAM_TEST_URL').'/__catalog_mode?mode='.$mode);
}
function catalogCounts(): array
{
    return json_decode(file_get_contents(getenv('UPSTREAM_TEST_URL').'/__request_counts'), true);
}
function catalogHttp(int $provider, ?string $token, string $query = ''): array
{
    $h = curl_init(getenv('TEST_BASE_URL').'/api/v1/admin/provider-connections/'.$provider.'/catalog'.$query);
    $headers = ['Accept: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer '.$token;
    }
    $cache = '';
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 30,
        CURLOPT_HEADERFUNCTION => static function ($h, $line) use (&$cache) {
            if (str_starts_with(strtolower($line), 'cache-control:')) $cache = trim(substr($line, 14));

            return strlen($line);
        }]);
    $r = curl_exec($h);
    $status = curl_getinfo($h, CURLINFO_RESPONSE_CODE);
    curl_close($h);

    return [$status, json_decode($r, true, 64, JSON_THROW_ON_ERROR), $cache];
}
function catalogReject(callable $fn, int $status, string $label): void
{
    try {
        $fn();
    } catch (ApiException $e) {
        catalogCheck($e->httpStatus === $status, $label);

        return;
    }
    catalogCheck(false, $label);
}
function catalogKey(int $provider): string
{
    global $db;
    $s = $db->prepare('SELECT * FROM providers WHERE id=?');
    $s->execute([$provider]);
    $p = $s->fetch();

    return 'catalog:p'.$provider.':'.Security::requestFingerprint(json_encode([config('app.env'), config('database.connections.mysql.database'), $provider, $p['driver'], $p['endpoint'], $p['credentials']], JSON_THROW_ON_ERROR));
}
function catalogFacts(): string
{
    global $db;
    $rows = [];
    foreach (['products', 'instances', 'invoices', 'transactions', 'tasks', 'admin_audit_logs'] as $table) {
        $rows[$table] = $db->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll();
    }

    return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
}
$auth = new AdminAuthService;
$admins = [];
$tokens = [];
foreach (['super_admin', 'support', 'finance', 'operator'] as $role) {
    $admins[$role] = $auth->create(['username' => 'catalog-'.$role, 'email' => 'catalog-'.$role.'@example.test', 'password' => 'CatalogAdmin!2026', 'role' => $role]);
    $tokens[$role] = Security::generateToken(['uid' => $admins[$role], 'sub_type' => 'admin', 'av' => AdminAuthService::sessionVersion($db, $admins[$role])]);
}
$admin = $admins['super_admin'];
$session = AdminAuthService::sessionVersion($db, $admin);
$token = $tokens['super_admin'];
$db->prepare('INSERT INTO providers(name,driver,endpoint,credentials) VALUES(?,?,?,?)')->execute(['目录隔离验收', 'zjmf', getenv('UPSTREAM_TEST_URL'), json_encode(['username' => Security::encrypt('test'), 'password' => Security::encrypt('test')])]);
$provider = (int) $db->lastInsertId();
$service = new UpstreamCatalogService;
$store = Cache::store('redis');
$key = catalogKey($provider);
$before = catalogCounts();
foreach (['support', 'finance', 'operator'] as $role) {
    catalogCheck(catalogHttp($provider, $tokens[$role])[0] === 403, $role.' cannot discover catalog');
}
catalogCheck(catalogHttp($provider, null)[0] === 401, 'anonymous catalog rejected');
$client = Security::generateToken(['uid' => 1, 'sub_type' => 'user']);
catalogCheck(catalogHttp($provider, $client)[0] === 401, 'customer token cannot read admin catalog');
catalogCheck(catalogCounts() === $before, 'rejected readers never call supplier');
catalogMode('normal');
$facts = catalogFacts();
[$status, $response, $headers] = catalogHttp($provider, $token);
$data = $response['data'];
catalogCheck($status === 200 && $response['code'] === 0 && str_contains($headers, 'no-store'), 'HTTP contract and no-store');
catalogCheck(count($data['items']) === 7 && count($data['categories']) === 2 && ! $data['cache_hit'], 'grouped candidates returned without configured count assumption');
$items = array_column($data['items'], null, 'remote_pid');
catalogCheck($items[391]['stock_state'] === 'unlimited' && $items[391]['stock_qty'] === null, 'disabled stock control and zero is unlimited');
catalogCheck($items[14]['stock_state'] === 'in_stock' && $items[14]['stock_qty'] === 5 && $items[14]['type'] === 'cloud', 'controlled stock and normalized cloud type');
catalogCheck($items[563]['stock_state'] === 'sold_out' && $items[563]['stock_qty'] === 0, 'zero stock preserves sold_out vocabulary');
catalogCheck($items[900]['type'] === 'unsupported' && $items[900]['stock_state'] === 'unknown', 'unknown type and malformed control are not guessed');
catalogCheck($items[901]['type'] === 'dedicated' && $items[901]['stock_qty'] === 2, 'dual stock limits take conservative minimum');
catalogCheck($items[902]['hidden'] && $items[902]['type'] === 'hosting' && $items[903]['stock_state'] === 'unknown', 'hidden and invalid quantity remain honest observations');
catalogCheck($items[391]['description'] === '纯文本介绍', 'HTML and script content stripped');
$raw = json_encode($data);
catalogCheck(! str_contains($raw, 'INTERNAL_SECRET') && ! str_contains($raw, '_connection_scope') && ! str_contains($raw, 'endpoint') && ! str_contains($raw, 'credentials') && ! str_contains($raw, '<script'), 'catalog projects no credentials, raw extra or HTML');
catalogCheck(catalogFacts() === $facts, 'discovery writes no products, lifecycle, money or audit');
$before = catalogCounts();
$cached = catalogHttp($provider, $token)[1]['data'];
catalogCheck($cached['cache_hit'] && catalogCounts() === $before, 'cache hit performs no upstream requests');
catalogCheck($store->getStore()->connection()->ttl($store->getStore()->getPrefix().$key) <= 600, 'catalog cache expires within ten minutes');
catalogCheck(catalogHttp($provider, $token, '?refresh=1')[0] === 200, 'explicit refresh can bypass valid cached candidates');
$before = catalogCounts();
catalogCheck(catalogHttp($provider, $token, '?refresh=1')[0] === 429 && catalogCounts() === $before, 'refresh cooldown rejects before upstream');
catalogCheck(catalogHttp($provider, $token, '?refresh[]=1')[0] === 400, 'invalid refresh shape rejected');
$db->prepare("UPDATE admins SET role='support' WHERE id=?")->execute([$admin]);
catalogCheck(catalogHttp($provider, $token)[0] === 403, 'cached response rechecks current role');
$db->prepare("UPDATE admins SET role='super_admin' WHERE id=?")->execute([$admin]);
$db->prepare('UPDATE providers SET status=0 WHERE id=?')->execute([$provider]);
catalogCheck(catalogHttp($provider, $token)[0] === 409, 'cache cannot bypass disabled connection');
$db->prepare('UPDATE providers SET status=1 WHERE id=?')->execute([$provider]);
catalogReject(fn () => $service->listing($admin, $session + 1, $provider), 401, 'service checks current session revision even on cache hit');
// Original preview + confirm pipeline, default hidden and atomic audit; no new import mechanism.
$products = new AdminProductService;
$input = ['provider_id' => $provider, 'remote_pid' => 391, 'name' => '目录导入CDN', 'type' => 'cdn', 'billing_cycles' => ['monthly'], 'markup_type' => 'none', 'markup_value' => '0.00'];
$preview = $products->preview($admin, $input);
$body = $input + ['preview_token' => $preview['preview_token'], 'evidence' => '隔离目录单品导入验收'];
$result = $products->snapshot($admin, $body);
$local = $result['product_id'];
$r = $db->query('SELECT status FROM products WHERE id='.$local)->fetchColumn();
catalogCheck($r === 'hidden', 'original import defaults to hidden');
$facts = catalogFacts();
$before = catalogCounts();
catalogCheck($products->snapshot($admin, $body)['product_id'] === $local && catalogFacts() === $facts && catalogCounts() === $before, 'same preview replay creates no product, audit or upstream call');
$overlay = array_column(catalogHttp($provider, $token)[1]['data']['items'], null, 'remote_pid');
catalogCheck($overlay[391]['imported'] && $overlay[391]['local_product_id'] === $local && $overlay[391]['local_status'] === 'hidden', 'cached directory overlays just committed local import immediately');
$db->prepare("UPDATE products SET status='active' WHERE id=?")->execute([$local]);
$overlay = array_column(catalogHttp($provider, $token)[1]['data']['items'], null, 'remote_pid');
catalogCheck($overlay[391]['local_status'] === 'active', 'local status change is never frozen in directory cache');
$different = $input;
$different['name'] = '不应覆盖旧名称';
$p = $products->preview($admin, $different);
$facts = catalogFacts();
catalogReject(fn () => $products->snapshot($admin, $different + ['preview_token' => $p['preview_token'], 'evidence' => '已有商品应拒绝覆盖']), 409, 'different import confirmation conflicts with existing binding');
catalogCheck(catalogFacts() === $facts, 'duplicate conflict does not modify original product or audit');
// Supplier disappearance is only a discovery change, not a local product lifecycle action.
$store->forget($key.':refresh');
catalogMode('removed');
$before = catalogFacts();
$d = $service->listing($admin, $session, $provider, true);
catalogCheck(! in_array(391, array_column($d['items'], 'remote_pid'), true) && catalogFacts() === $before, 'supplier disappearance does not retire or delete local product');
foreach (['bad_id', 'duplicate', 'unavailable'] as $mode) {
    catalogMode($mode);
    $store->forget($key.':refresh');
    catalogReject(fn () => $service->listing($admin, $session, $provider, true), 503, 'supplier '.$mode.' fails safely');
}
catalogMode('normal');
$store->forget($key.':refresh');
$store->put($key, ['at' => time() - 601, 'items' => []], 600);
$before = catalogCounts();
$service->listing($admin, $session, $provider);
catalogCheck((catalogCounts()['/cart/all'] ?? 0) === ($before['/cart/all'] ?? 0) + 1, 'expired snapshot requires a new read');
$store->put($key, ['at' => time(), 'items' => [['extra' => 'SECRET']]], 600);
$d = $service->listing($admin, $session, $provider);
catalogCheck(count($d['items']) === 7 && ! $d['cache_hit'], 'corrupt projection cannot be returned');
$credentialJson = $db->query('SELECT credentials FROM providers WHERE id='.$provider)->fetchColumn();
$db->prepare('UPDATE providers SET credentials=? WHERE id=?')->execute([json_encode(['username' => Security::encrypt('test'), 'password' => Security::encrypt('new-revision')]), $provider]);
$d = $service->listing($admin, $session, $provider);
catalogCheck(! $d['cache_hit'], 'credential ciphertext revision isolates catalog cache');
$db->prepare('UPDATE providers SET credentials=? WHERE id=?')->execute([$credentialJson, $provider]);
$lock = $store->lock($key.':loading', 60);
$store->forget($key);
$lock->get();
try {
    catalogReject(fn () => $service->listing($admin, $session, $provider), 409, 'concurrent cache miss is bounded by framework lock');
} finally {
    $lock->release();
}
$service->listing($admin, $session, $provider);
// Recheck after slow remote read, not only before it starts.
catalogMode('delay');
$store->forget($key.':refresh');
$child = proc_open([PHP_BINARY, '-r', 'usleep(500000); $c=new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=jianyun_test",getenv("DB_USERNAME"),getenv("DB_PASSWORD")); $c->prepare("UPDATE providers SET status=0 WHERE id=?")->execute([(int)$argv[1]]);', (string) $provider], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
catalogReject(fn () => $service->listing($admin, $session, $provider, true), 409, 'slow read rechecks disabled provider before returning');
foreach ($pipes as $pipe) fclose($pipe);
catalogCheck(proc_close($child) === 0, 'concurrent disable process completed');
$db = Database::getInstance();
$db->prepare('UPDATE providers SET status=1 WHERE id=?')->execute([$provider]);
catalogMode('empty');
$store->forget($key.':refresh');
catalogCheck($service->listing($admin, $session, $provider, true)['items'] === [], 'empty catalog is a valid empty state');
catalogMode('normal');
$original = Cache::getFacadeRoot();
Cache::swap(new class
{
    public function store($name): never
    {
        throw new RuntimeException('offline');
    }
});
try {
    catalogReject(fn () => $service->listing($admin, $session, $provider), 503, 'cache outage is explicit, never returns stale success');
} finally {
    Cache::swap($original);
}
// Optional interface: existing non-discovery drivers keep their original paths.
$db->prepare('INSERT INTO providers(name,driver,endpoint) VALUES(?,?,?)')->execute(['手工不支持目录', 'manual', '']);
$manual = (int) $db->lastInsertId();
catalogReject(fn () => $service->listing($admin, $session, $manual), 409, 'manual driver need not invent empty catalog capability');
foreach ([0, -1, 2147483648] as $bad) {
    try {
        new UpstreamCatalogItem($bad, 'bad', 'group', 'cloud', 'unknown', observedAt: time());
        catalogCheck(false, 'bad PID');
    } catch (InvalidArgumentException) {
        catalogCheck(true, 'DTO rejects PID '.$bad);
    }
}
echo 'Upstream catalog checks: '.$checks.PHP_EOL;
