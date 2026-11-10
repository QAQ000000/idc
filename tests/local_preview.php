<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
set_exception_handler(static function (Throwable $e): never {
    fwrite(STDERR, $e->getMessage()."\n".$e->getTraceAsString()."\n");
    exit(1);
});
use App\Core\{Database,Security,ApiException};
use App\Services\{CatalogService,ProductSnapshotService,ProductRefreshService,BillingService};

if (config('app.env') !== 'test' || config('database.connections.mysql.database') !== 'jianyun_test') {
    throw new RuntimeException('Disposable DB required');
}
$db = Database::getInstance();
$catalog = new CatalogService();
$checks = 0;
$up = getenv('UPSTREAM_TEST_URL');
function check(bool $ok, string $label): void
{
    global $checks;
    if (! $ok)throw new RuntimeException('FAIL PREVIEW: '.$label);
    $checks++;
    echo "PASS PREVIEW: $label\n";
}
function counts(): array
{
    global $up;

    return json_decode(file_get_contents($up.'/__request_counts'), true);
}
function mode(string $v): void
{
    global $up;
    file_get_contents($up.'/__config_mode?mode='.$v);
}
function field(string $sql, array $args = []): mixed
{
    global $db;
    $s = $db->prepare($sql);
    $s->execute($args);

    return $s->fetchColumn();
}
function httpPreview(int $pid, string $cycle, array $opts): array
{
    $body = file_get_contents(getenv('TEST_BASE_URL').'/api/v1/products/'.$pid.'/preview', false, stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: application/json', 'content' => json_encode(['billing_cycle' => $cycle, 'config_options' => $opts]), 'ignore_errors' => true]]));

    return json_decode($body, true, 64, JSON_THROW_ON_ERROR);
}
function rejection(callable $fn, string $label): void
{
    try {
        $fn();
    } catch (ApiException) {
        check(true, $label);

        return;
    }throw new RuntimeException('Expected rejection '.$label);
}
function observation(int $pid, ?int $valid = null): void
{
    global $db;
    $cache = json_decode(field('SELECT config_cache FROM products WHERE id=?', [$pid]), true);
    $meta = ['result' => 'unchanged', 'last_attempt_at' => time(), 'last_success_at' => time(), 'valid_until' => $valid ?? time() + 900, 'snapshot_hash' => hash('sha256', ProductSnapshotService::canonical($cache))];
    $db->prepare("INSERT INTO system_settings(`key`,`group`,value) VALUES(?,'catalog',?) ON DUPLICATE KEY UPDATE value=VALUES(value)")->execute(['product.refresh.'.$pid, json_encode($meta)]);
}
mode('normal');
config(['jianyun.product_local_preview' => true]);
$db->prepare('INSERT INTO providers(name,driver,endpoint,credentials) VALUES(?,?,?,?)')->execute(['Local preview fixture', 'zjmf', $up, json_encode(['username' => Security::encrypt('fixture'), 'password' => Security::encrypt('fixture')])]);
$provider = (int) $db->lastInsertId();
$input = ['provider_id' => $provider, 'remote_pid' => 389, 'name' => 'Customer local preview fixture', 'type' => 'cloud', 'billing_cycles' => ['monthly']];
$pid = (new ProductSnapshotService())->save($input)['product_id'];
$cache = json_decode(field('SELECT config_cache FROM products WHERE id=?', [$pid]), true);
$opts = $cache['default_config'];
$before = counts();
check($catalog->preview($pid, 'monthly', $opts) === ['pricing_mode' => 'online_required'], 'missing successful observation requests online without partial prices');
check(counts() === $before, 'unavailable local preview makes zero supplier requests');
config(['jianyun.product_refresh_ids' => (string) $pid]);
$refreshed = (new ProductRefreshService())->runBatch();
check($refreshed['items'][0]['result'] === 'unchanged', 'complete existing snapshot validated by real refresh service');
$before = counts();
$q = $catalog->preview($pid, 'monthly', $opts);
check($q['pricing_mode'] === 'local_preview' && $q['total'] === '257.00' && $q['recurring_price'] === '257.00', 'fresh snapshot previews default price');
check(str_starts_with($q['quote_token'], 'lp1.') && ! isset($q['product'],$q['upstream_total'],$q['pricing_hash'],$q['schema']), 'preview exposes purpose-bound confirmation and safe fields only');
$r = httpPreview($pid, 'monthly', $opts);
check($r['code'] === 0 && $r['data']['pricing_mode'] === 'local_preview', 'public HTTP route uses current local snapshot');
foreach (['1', '2', '3', '4', '5'] as $qty) {
    $selected = $opts;
    $selected['277296'] = $qty;
    $r = $catalog->preview($pid, 'monthly', $selected);
    check($r['pricing_mode'] === 'local_preview' && $r['config_options']['277296'] === $qty, 'new quantity combination locally calculated: '.$qty);
}
check(counts() === $before, 'normal default, new combinations and both quantity bounds invoke zero supplier requests');
rejection(fn () => $catalog->preview($pid, 'annually', $opts), 'undeclared billing cycle cannot be previewed');
foreach (['0', '6', '1.5', '2e0'] as $bad) {
    $sel = $opts;
    $sel['277296'] = $bad;
    rejection(fn () => $catalog->preview($pid, 'monthly', $sel), 'invalid quantity rejected: '.$bad);
}
config(['jianyun.product_local_preview' => false]);
check($catalog->preview($pid, 'monthly', $opts) === ['pricing_mode' => 'online_required'], 'feature off explicitly requests online');
config(['jianyun.product_local_preview' => true]);
observation($pid, time() - 1);
check($catalog->preview($pid, 'monthly', $opts) === ['pricing_mode' => 'online_required'], 'expired snapshot never returns stale preview');
observation($pid, time() + 60);
$short = $catalog->preview($pid, 'monthly', $opts);
check((int) explode('.', $short['quote_token'])[1] <= time() + 60, 'preview expiry cannot exceed freshness window');
observation($pid);
$saved = field('SELECT config_cache FROM products WHERE id=?', [$pid]);
$broken = json_decode($saved, true);
unset($broken['schema']['product_pricings']);
$db->prepare('UPDATE products SET config_cache=? WHERE id=?')->execute([json_encode($broken), $pid]);
check($catalog->preview($pid, 'monthly', $opts) === ['pricing_mode' => 'online_required'], 'changed snapshot hash invalidates old freshness');
observation($pid);
check($catalog->preview($pid, 'monthly', $opts) === ['pricing_mode' => 'online_required'], 'incomplete price matrix cannot produce any local price');
$db->prepare('UPDATE products SET config_cache=? WHERE id=?')->execute([$saved, $pid]);
observation($pid);
$q = $catalog->preview($pid, 'monthly', $opts);
$session = (new App\Services\AuthService())->register(['email' => 'local-preview@example.test', 'password' => 'PreviewCustomer!2026']);
$uid = (int) $session['user']['id'];
(new App\Services\DemoService())->recharge($uid, '1000.00', 'local-preview-funds');
$order = ['product_id' => $pid, 'billing_cycle' => 'monthly', 'config_options' => $opts, 'hostname' => 'preview-instance', 'password' => 'PreviewPassword12', 'quote_token' => $q['quote_token'], 'checkout_key' => 'local-preview-first-order', 'use_balance' => true];
$before = counts();
$invoice = (new BillingService())->checkout($uid, $order);
$after = counts();
check($after['/cart/get_total'] - $before['/cart/get_total'] === 1, 'checkout performs one authoritative online total calculation');
check($invoice['status'] === 'paid' && field('SELECT balance FROM users WHERE id=?', [$uid]) === '743.00', 'confirmed preview settles the online amount');
check((int) field('SELECT COUNT(*) FROM tasks WHERE invoice_id=?', [$invoice['invoice_id']]) === 1, 'payment and single create outbox committed together');
mode('refresh_unavailable');
$before = counts();
$invoice2 = (new BillingService())->checkout($uid, $order);
check($invoice2['invoice_id'] === $invoice['invoice_id'] && counts() === $before, 'same request key restores settled original without supplier even during outage');
check(field('SELECT balance FROM users WHERE id=?', [$uid]) === '743.00', 'key replay never repeats wallet debit');
mode('normal');
$q = $catalog->preview($pid, 'monthly', $opts);
$order['checkout_key'] = 'local-preview-second-order';
$order['quote_token'] = $q['quote_token'];
$baseline = [field('SELECT balance FROM users WHERE id=?', [$uid]), field('SELECT COUNT(*) FROM invoices'), field('SELECT COUNT(*) FROM instances'), field('SELECT COUNT(*) FROM tasks')];
foreach (['refresh_rule_price', 'pricing_mismatch'] as $fault) {
    mode($fault);
    rejection(fn () => (new BillingService())->checkout($uid, $order), 'first or recurring change cannot settle old preview: '.$fault);
}
check($baseline === [field('SELECT balance FROM users WHERE id=?', [$uid]), field('SELECT COUNT(*) FROM invoices'), field('SELECT COUNT(*) FROM instances'), field('SELECT COUNT(*) FROM tasks')], 'rejected changed prices leave all financial and resource facts intact');
$online = $catalog->calculate($pid, 'monthly', $opts);
check($online['pricing_mode'] === 'online' && $online['recurring_price'] === '999.99', 'forced online quote gives changed renewal amount for explicit reconfirmation');
mode('normal');
mode('preview_generation');
rejection(fn () => (new BillingService())->checkout($uid, $order), 'unchanged amounts cannot bypass changed upstream provisioning rules');
mode('normal');
foreach ([substr($q['quote_token'], 0, -1).($q['quote_token'][-1] === 'a' ? 'b' : 'a'), 'lp1.'.(time() - 1).'.'.str_repeat('a', 64)] as $token) {
    $bad = $order;
    $bad['quote_token'] = $token;
    rejection(fn () => (new BillingService())->checkout($uid, $bad), 'tampered or expired local token rejected');
}
$bad = $order;
$bad['config_options']['277296'] = '2';
rejection(fn () => (new BillingService())->checkout($uid, $bad), 'token cannot authorize a different combination');
$db->prepare("UPDATE products SET markup_type='fixed',markup_value='1.00' WHERE id=?")->execute([$pid]);
rejection(fn () => (new BillingService())->checkout($uid, $order), 'concurrent markup edit rejects prior local confirmation');
$before = counts();
$edited = $catalog->preview($pid, 'monthly', $opts);
check(counts() === $before && $edited['total'] === '258.00', 'local preview applies current markup without supplier calls');
$db->prepare("UPDATE products SET markup_type='none',markup_value='0.00' WHERE id=?")->execute([$pid]);
observation($pid, time() - 1);
mode('refresh_unavailable');
$before = counts();
$r = httpPreview($pid, 'monthly', $opts);
check($r['data'] === ['pricing_mode' => 'online_required'] && counts() === $before, 'HTTP expiration does not invoke supplier or return stale money');
rejection(fn () => $catalog->calculate($pid, 'monthly', $opts), 'online failure cannot fall back to stale local preview');
mode('normal');
$unsupported = (new ProductSnapshotService())->save(array_replace($input, ['remote_pid' => 14, 'billing_cycles' => ['monthly'], 'name' => 'Unsupported preview fixture']))['product_id'];
$form = $catalog->configuration($unsupported);
observation($unsupported);
check($catalog->preview($unsupported, 'monthly', $form['defaults']) === ['pricing_mode' => 'online_required'], 'unsupported policy or matrix falls back even with a fresh marker');
$db->prepare("UPDATE products SET status='hidden' WHERE id=?")->execute([$pid]);
rejection(fn () => $catalog->preview($pid, 'monthly', $opts), 'hidden product cannot be locally previewed');
$db->prepare("UPDATE products SET status='active',stock=0 WHERE id=?")->execute([$pid]);
rejection(fn () => $catalog->preview($pid, 'monthly', $opts), 'local quota zero remains a hard gate');
$db->prepare('UPDATE products SET stock=-1 WHERE id=?')->execute([$pid]);
observation($pid);
config(['jianyun.product_refresh_ids' => '']);
echo "Local preview checks: $checks\n";
