<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use App\Core\{ApiException,Database,RedisStore,Security};
use App\Plugins\Providers\{StockObservation,ProviderRegistry,ConfigOptions};
use App\Services\{CatalogService,ProductSnapshotService,StockObservationService,BillingService,OutboxWorker};
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Cache;
use Illuminate\Events\Dispatcher;
use Illuminate\Redis\Events\CommandExecuted;

if (getenv('APP_ENV') !== 'test' || getenv('DB_DATABASE') !== 'jianyun_test') {
    throw new RuntimeException('Isolated DB required');
}
$db = Database::getInstance();
$checks = 0;
$base = getenv('UPSTREAM_TEST_URL');
function check(bool $ok, string $label): void
{
    global $checks;
    if (! $ok)throw new RuntimeException('FAIL STOCK: '.$label);
    $checks++;
    echo "PASS STOCK: $label\n";
}
function mode(string $v): void
{
    global $base;
    file_get_contents($base.'/__config_mode?mode='.$v);
}
function counts(): array
{
    global $base;

    return json_decode(file_get_contents($base.'/__request_counts'), true);
}
function listing(int $id): array
{
    return array_values(array_filter((new CatalogService)->products(), fn ($p) => (int) $p['id'] === $id))[0];
}
function rejected(callable $fn, int $code, string $label): void
{
    try {
        $fn();
    } catch (ApiException $e) {
        check($e->getCode() === $code, $label);

        return;
    }throw new RuntimeException('Expected error: '.$label);
}
$db->prepare('INSERT INTO providers(name,driver,endpoint,credentials) VALUES(?,?,?,?)')->execute(['Stock fixture', 'zjmf', $base, json_encode(['username' => Security::encrypt('test'), 'password' => Security::encrypt('test')])]);
$provider = (int) $db->lastInsertId();
$snapshot = new ProductSnapshotService;
$catalog = new CatalogService;
$stocks = new StockObservationService;
mode('stock_one');
$saved = $snapshot->save(['provider_id' => $provider, 'remote_pid' => 14, 'name' => '选购库存隔离商品', 'type' => 'cloud']);
$pid = $saved['product_id'];
$options = $catalog->configuration($pid)['defaults'];
$q = $catalog->calculate($pid, 'monthly', $options);
check($q['upstream_stock']['state'] === 'in_stock' && $q['upstream_stock']['quantity'] === 1, 'quote returns reliable upstream count with timestamp');
check($q['upstream_stock']['checked_at'] !== null, 'observation has server check time');
$before = counts();
$p = listing($pid);
check(counts() === $before, 'directory reads never perform upstream calls');
check($p['upstream_stock'] === $q['upstream_stock'], 'directory and quote share cached observation');
check((int) $p['stock'] === -1, 'upstream count never overwrites local unlimited quota');
check(! isset($p['provider_id'],$p['remote_pid'],$p['credentials'],$p['config_cache']), 'public directory excludes provider binding and raw schema');
$cache = $db->query('SELECT config_cache FROM products WHERE id='.$pid)->fetchColumn();
mode('stock_two');
$pre = $catalog->preflight($pid, 'monthly', $options);
$next = $catalog->quote($db, $pid, 'monthly', $options, false, $pre);
$catalog->assertConfirmed($next, $pre, $q['quote_token']);
check(true, 'stock-only count change preserves original quote confirmation');
check(listing($pid)['upstream_stock']['quantity'] === 2, 'new successful quote refreshes directory observation');
check($cache === $db->query('SELECT config_cache FROM products WHERE id='.$pid)->fetchColumn(), 'observation does not alter snapshot or sales configuration');
mode('stock_unlimited_zero');
$u = $catalog->calculate($pid, 'monthly', $options);
check($u['upstream_stock']['state'] === 'unlimited' && $u['upstream_stock']['quantity'] === null, 'disabled control with qty0 means unlimited rather than sold out');
mode('stock_empty');
rejected(fn () => $catalog->calculate($pid, 'monthly', $options), 1003, 'explicit sold out has distinct business code');
check(listing($pid)['upstream_stock']['state'] === 'sold_out' && listing($pid)['upstream_stock']['quantity'] === 0, 'failed stock preflight still publishes confirmed sold out');
mode('unavailable');
rejected(fn () => $catalog->calculate($pid, 'monthly', $options), 1004, 'transport/query failure differs from confirmed stock failure');
check(listing($pid)['upstream_stock']['state'] === 'unknown', 'query failure invalidates prior sold-out evidence');
mode('stock_bad');
rejected(fn () => $catalog->calculate($pid, 'monthly', $options), 1004, 'malformed controlled quantity is not reported as zero');
foreach (['stock_empty', 'unavailable'] as $m) {
    mode($m);
    $h = curl_init(getenv('TEST_BASE_URL').'/api/v1/products/'.$pid.'/calculate');
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['billing_cycle' => 'monthly', 'config_options' => $options])]);
    $r = json_decode(curl_exec($h), true);
    $status = curl_getinfo($h, CURLINFO_RESPONSE_CODE);
    curl_close($h);
    check($status === 409 && $r['code'] === ($m === 'stock_empty' ? 1003 : 1004), 'HTTP preserves distinct stock versus query error '.$m);
}
mode('stock_two');
$fresh = $catalog->calculate($pid, 'monthly', $options);
$db->prepare('UPDATE products SET stock=0 WHERE id=?')->execute([$pid]);
rejected(fn () => $catalog->calculate($pid, 'monthly', $options), 1002, 'local quota remains an independent authoritative gate');
check(listing($pid)['upstream_stock']['quantity'] === 2, 'local quota failure does not invent upstream sold out');
$db->prepare('UPDATE products SET stock=-1 WHERE id=?')->execute([$pid]);
$db->prepare('INSERT INTO users(email,password,balance) VALUES(?,?,100)')->execute(['stock-'.bin2hex(random_bytes(4)).'@example.test', Security::hashPassword('StockTestPassword1')]);
$user = (int) $db->lastInsertId();
$input = ['product_id' => $pid, 'billing_cycle' => 'monthly', 'config_options' => $options, 'hostname' => 'stock-test', 'password' => 'StockPassword123', 'use_balance' => true, 'checkout_key' => 'stock-gate-test-01', 'quote_token' => $fresh['quote_token']];
mode('stock_empty');
rejected(fn () => (new BillingService)->checkout($user, $input), 1003, 'checkout still refuses confirmed upstream sold out');
check((int) $db->query('SELECT COUNT(*) FROM invoices WHERE user_id='.$user)->fetchColumn() === 0 && $db->query('SELECT balance FROM users WHERE id='.$user)->fetchColumn() === '100.00', 'failed preflight creates no bill or debit');
mode('stock_two');
$input['quote_token'] = $catalog->calculate($pid, 'monthly', $options)['quote_token'];
$invoice = (new BillingService)->checkout($user, $input);
$db->prepare('UPDATE tasks SET max_retries=0 WHERE invoice_id=?')->execute([$invoice['invoice_id']]);
$before = counts();
mode('stock_empty');
(new OutboxWorker)->runOnce('stock-worker');
check((counts()['/cart/settle'] ?? 0) === ($before['/cart/settle'] ?? 0), 'Worker stock preflight prevents remote settlement');
$s = $db->prepare('SELECT status,execution_token FROM tasks WHERE invoice_id=?');
$s->execute([$invoice['invoice_id']]);
$task = $s->fetch();
check($task['status'] === 'failed' && (int) $task['execution_token'] > 0, 'Worker actually attempts preflight and leaves missing resource for review');
$key = 'jianyun:stock:'.hash('sha256', config('app.key').'|'.config('database.connections.mysql.database').'|'.$provider.'|14');
$cacheStore = Cache::store('redis');
$cacheStore->forget($key);
(new RedisStore)->command('SET', $key, json_encode(['state' => 'in_stock', 'quantity' => 99, 'checked_at' => gmdate('Y-m-d H:i:s')]), 'EX', '300');
check($stocks->read($provider, 14)['state'] === 'unknown', 'legacy raw cache is a cold miss without dual read or migration');
$cacheStore->put($key, json_encode(['state' => 'in_stock', 'quantity' => 9, 'checked_at' => gmdate('Y-m-d H:i:s', time() - 301)]), 300);
check($stocks->read($provider, 14)['state'] === 'unknown', 'expired observation is unknown even if cache key remains');
$cacheStore->put($key, '{"state":"bad"}', 300);
check($stocks->read($provider, 14)['state'] === 'unknown', 'malformed cache does not leak fields or break directory');
$cacheStore->put($key, json_encode(['state' => 'in_stock', 'quantity' => 9, 'checked_at' => gmdate('Y-m-d H:i:s', time() + 60)]), 300);
check($stocks->read($provider, 14)['state'] === 'unknown', 'future observation is not trusted');
$current = $stocks->remember($provider, 14, new StockObservation('in_stock', 3));
$stocks->remember($provider, 15, new StockObservation('sold_out', 0));
$stocks->remember($provider + 10000, 14, new StockObservation('unlimited'));
$connection = Redis::connection();
$ttl = $connection->ttl($cacheStore->getStore()->getPrefix().$key);
check($ttl > 0 && $ttl <= 300, 'framework cache applies a five minute Redis TTL');
$dispatcher = $connection->getEventDispatcher();
$events = new Dispatcher;
$commands = [];
$events->listen(CommandExecuted::class, function ($event) use (&$commands): void {
    $commands[] = strtolower($event->command);
});
$connection->setEventDispatcher($events);
try {
    check($stocks->readMany([]) === [] && $commands === [], 'empty directory performs no cache read');
    $batch = $stocks->readMany(['second' => [$provider, 15], 73 => [$provider, 14], 'same' => [$provider, 14], 'other' => [$provider + 10000, 14], 'missing' => [$provider, 16]]);
    check($commands === ['mget'], 'directory batches all bindings into one framework MGET');
    check(array_keys($batch) === ['second', 73, 'same', 'other', 'missing'] && $batch[73] === $current && $batch['same'] === $current, 'batch preserves caller keys, order and duplicate bindings');
    check($batch['second']['state'] === 'sold_out' && $batch['other']['state'] === 'unlimited' && $batch['missing']['state'] === 'unknown', 'provider and remote product identities remain isolated');
} finally {
    if ($dispatcher) {
        $connection->setEventDispatcher($dispatcher);
    } else {
        $connection->unsetEventDispatcher();
    }
}
$identity = config('database.connections.mysql.database');
try {
    config(['database.connections.mysql.database' => $identity.'-other']);
    check($stocks->read($provider, 14)['state'] === 'unknown', 'database identity isolates shared Redis observations');
} finally {
    config(['database.connections.mysql.database' => $identity]);
}
$root = Redis::getFacadeRoot();
Redis::swap(new class implements \Illuminate\Contracts\Redis\Factory
{
    public function connection($name = null): never
    {
        throw new RuntimeException('offline');
    }
});
// Cache stores retain their Redis factory: recreate it to exercise the failing boundary.
Cache::forgetDriver('redis');
try {
    check($stocks->read($provider, 14)['state'] === 'unknown', 'Redis outage degrades inventory only');
    $stocks->remember($provider, 14, new StockObservation('in_stock', 3));
    check(true, 'cache write outage never breaks successful domain operation');
} finally {
    Redis::swap($root);
    Cache::forgetDriver('redis');
}
check($stocks->read($provider, 14) === $current, 'cache becomes readable after Redis factory recovery');
mode('normal');
echo 'Checkout responsiveness checks: '.$checks.PHP_EOL;
