<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use App\Core\{Database,Security};
use App\Services\{AdminAuthService,AdminQueryService,AuthService,BillingService,DemoService};

if (config('app.env') !== 'test' || config('database.connections.mysql.database') !== 'jianyun_test')throw new RuntimeException('Isolated database required');
$db = Database::getInstance();
$checks = 0;
function aqCheck(bool $ok, string $label): void
{
    global $checks;
    if (! $ok)throw new RuntimeException('FAIL: '.$label);
    $checks++;
    echo 'PASS: '.$label.PHP_EOL;
}
function aqHttp(string $path, ?string $token = null, string $method = 'GET'): array
{
    $h = curl_init(getenv('TEST_BASE_URL').'/api/v1'.$path);
    $headers = ['Accept: application/json'];
    if ($token)$headers[] = 'Authorization: Bearer '.$token;
    $cache = '';
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 15, CURLOPT_HEADERFUNCTION => static function ($h, $line) use (&$cache) {
        if (str_starts_with(strtolower($line), 'cache-control:'))$cache = trim(substr($line, 14));

        return strlen($line);
    }]);
    $raw = curl_exec($h);
    $code = curl_getinfo($h, CURLINFO_RESPONSE_CODE);
    curl_close($h);

    return [$code, json_decode($raw, true, 32, JSON_THROW_ON_ERROR)['data'] ?? null, $cache];
}
function aqSnapshot(): string
{
    global $db;
    $out = [];
    foreach (['users', 'instances', 'invoices', 'tasks', 'transactions', 'payment_records', 'admin_audit_logs'] as $t)$out[$t] = $db->query("SELECT * FROM $t ORDER BY id")->fetchAll();

    return hash('sha256', json_encode($out));
}
$auth = new AuthService();
$a = $auth->register(['email' => 'query-customer-a@example.test', 'password' => 'QueryClient!2026']);
$b = $auth->register(['email' => 'query-customer-b@example.test', 'password' => 'QueryClient!2026']);
$uid = $a['user']['id'];
$other = $b['user']['id'];
$tokens = [];
$ids = [];
$admins = new AdminAuthService();
foreach (['super_admin', 'finance', 'support', 'operator'] as $role) {
    $id = $admins->create(['username' => 'query-'.$role, 'email' => 'query-'.$role.'@example.test', 'password' => 'QueryAdmin!2026', 'role' => $role]);
    $ids[$role] = $id;
    $tokens[$role] = Security::generateToken(['uid' => $id, 'sub_type' => 'admin', 'role' => 'super_admin']);
}
$demo = new DemoService();
$demo->recharge($uid, '100', 'query-fixture');
$demo->recharge($other, '100', 'query-other');
$pid = $demo->seed();
$billing = new BillingService();
$orders = [];
foreach ([$uid, $uid, $other] as $n => $owner) {
    $v = $billing->checkout($owner, ['product_id' => $pid, 'hostname' => 'query-fixture-'.$n, 'password' => 'QueryRoot!2026', 'billing_cycle' => 'monthly', 'use_balance' => true]);
    $orders[] = $v;
    $db->prepare("UPDATE instances SET name=?,ip_address=?,remote_instance_id=?,config_snapshot=JSON_SET(config_snapshot,'$.secret','QUERY_PRIVATE_CONFIG') WHERE id=?")->execute([$n === 0 ? 'query%_!literal' : 'query-other-'.$n, $n === 0 ? '2001:db8::1234' : '192.0.2.'.($n + 1), 'query:host-'.$n, $v['instance_id']]);
}
$i = $orders[0]['instance_id'];
$inv = $orders[0]['invoice_id'];
$task = (int) $db->query('SELECT id FROM tasks WHERE invoice_id='.(int) $inv)->fetchColumn();
$db->prepare("UPDATE tasks SET status='failed',execution_token=7,last_error='MANUAL_AUDIT_REQUIRED:NON_QUERYABLE' WHERE id=?")->execute([$task]);
$db->prepare("INSERT INTO admin_audit_logs(admin_id,action,target_type,target_id,context) VALUES(?,'fixture','task',?,?)")->execute([$ids['super_admin'], $task, json_encode(['evidence' => 'QUERY-AUDIT: original resource verified', 'previous_execution_token' => 6, 'amount' => '9.99', 'payment_record_id' => 999, 'secret' => 'QUERY_PRIVATE_AUDIT'])]);
$db->prepare("INSERT INTO invoices(user_id,instance_id,type,total,status) VALUES(?,?,'renew','10.00','unpaid')")->execute([$uid, $i]);
// Deliberately malformed ownership must not be associated with this customer's instance.
$db->prepare("INSERT INTO invoices(user_id,instance_id,type,total,status) VALUES(?,?,'renew','10.00','unpaid')")->execute([$other, $i]);
$foreign = (int) $db->lastInsertId();
$before = aqSnapshot();
$admin = $tokens['super_admin'];
$paths = ['/admin/customers', '/admin/customers/'.$uid, '/admin/instances', '/admin/instances/'.$i];
foreach ($paths as $path) {
    aqCheck(aqHttp($path)[0] === 401 && aqHttp($path, $a['token'])[0] === 401, 'anonymous and customer rejected: '.$path);
    foreach ($tokens as $role => $token) {
        [$code,,$cache] = aqHttp($path, $token);
        aqCheck($code === 200 && str_contains($cache, 'no-store'), $role.' read allowed without caching: '.$path);
    }
}
$r = aqHttp('/admin/customers?email='.urlencode($a['user']['email']), $admin)[1];
aqCheck($r['total'] === 1 && $r['items'][0]['id'] === $uid, 'email locates exact original customer');
aqCheck(aqHttp('/admin/customers?email=query-customer-a', $admin)[1]['total'] === 0, 'email is exact, not substring');
aqCheck(aqHttp('/admin/customers?user_id='.$other.'&email='.urlencode($a['user']['email']), $admin)[1]['total'] === 0, 'customer ID and email intersect');
aqCheck(aqHttp('/admin/customers?email=missing%40example.test', $admin)[1]['items'] === [], 'empty customer result');
$p1 = aqHttp('/admin/customers?per_page=1', $admin)[1];
$p2 = aqHttp('/admin/customers?per_page=1&page=2', $admin)[1];
aqCheck(count($p1['items']) === 1 && $p1['items'][0]['id'] > $p2['items'][0]['id'], 'stable descending customer pagination');
aqCheck(aqHttp('/admin/customers?page=100000', $admin)[1]['items'] === [], 'out-of-range customer page empty');
$d = aqHttp('/admin/customers/'.$uid.'?per_page=1&user_id='.$other, $admin)[1];
aqCheck($d['instances']['total'] === 2 && count($d['instances']['items']) === 1 && $d['instances']['items'][0]['user_id'] === $uid, 'customer detail fixed ownership with independent pagination');
aqCheck(aqHttp('/admin/customers/'.$uid.'?per_page=1&page=3', $admin)[1]['instances']['items'] === [], 'customer instance page beyond end empty');
foreach (['instance_id' => $i, 'name' => '%_!', 'ip_address' => '2001:db8::1234', 'remote_instance_id' => 'query:host-0'] as $key => $value) {
    $r = aqHttp('/admin/instances?'.http_build_query([$key => $value]), $admin)[1];
    aqCheck($r['total'] === 1 && $r['items'][0]['id'] === $i, 'instance search matches literal '.$key);
}
aqCheck(aqHttp('/admin/instances?user_id='.$other.'&instance_id='.$i, $admin)[1]['total'] === 0, 'instance owner filter cannot cross customers');
aqCheck(aqHttp('/admin/instances?remote_instance_id=host-0', $admin)[1]['total'] === 0, 'remote ID search is exact and namespaced');
aqCheck(aqHttp('/admin/instances?name='.urlencode("' OR 1=1 --"), $admin)[1]['total'] === 0, 'name query is parameterized');
$d = aqHttp('/admin/instances/'.$i.'?per_page=1', $admin)[1];
aqCheck($d['instance']['user_id'] === $uid && $d['instance']['product_id'] === $pid, 'instance detail returns owner and product');
aqCheck($d['invoices']['total'] === 2 && count($d['invoices']['items']) === 1, 'instance invoice list paginated and excludes malformed ownership');
$d2 = aqHttp('/admin/instances/'.$i.'?per_page=1&page=2', $admin)[1];
aqCheck($d['invoices']['items'][0]['invoice_id'] !== $d2['invoices']['items'][0]['invoice_id'], 'invoice pagination has no duplicated rows');
function aqKeys(array $data): array
{
    $keys = [];
    foreach ($data as $k => $v) {
        $keys[] = (string) $k;
        if (is_array($v))array_push($keys, ...aqKeys($v));
    }

    return $keys;
}
foreach ($paths as $path) {
    $data = aqHttp($path, $admin)[1];
    $keys = aqKeys($data);
    aqCheck(! array_intersect($keys, ['password', 'encrypted_password', 'config_snapshot', 'credentials', 'endpoint', 'balance', 'total_spent', 'recurring_price', 'override_price', 'total_amount', 'payment_method', 'payload', 'auth_version']) && ! str_contains(json_encode($data), 'QUERY_PRIVATE'), 'new query omits sensitive and financial data: '.$path);
}
foreach (['/admin/customers/999999999', '/admin/instances/999999999'] as $path)aqCheck(aqHttp($path, $admin)[0] === 404, 'missing detail 404: '.$path);
foreach (['page=0', 'page[]=1', 'page=100001', 'per_page=101', 'per_page[]=10', 'user_id=-1', 'email[]=x', 'email='.str_repeat('x', 101)] as $query)aqCheck(aqHttp('/admin/customers?'.$query, $admin)[0] === 400, 'invalid customer filter '.$query);
foreach (['instance_id=1+OR+1%3D1', 'user_id[]=1', 'name[]=x', 'remote_instance_id[]=x', 'ip_address=bad', 'ip_address[]=x', 'name='.str_repeat('x', 101)] as $query)aqCheck(aqHttp('/admin/instances?'.$query, $admin)[0] === 400, 'invalid instance filter '.$query);
aqCheck(aqHttp('/admin/customers/'.$uid.'?page=0', $admin)[0] === 400 && aqHttp('/admin/instances/'.$i.'?per_page=101', $admin)[0] === 400, 'detail pagination validated');
foreach ($paths as $path)foreach (['POST', 'PATCH', 'DELETE'] as $method)aqCheck(aqHttp($path, $admin, $method)[0] === 405, 'new entry read only: '.$method.' '.$path);
$ts = aqHttp('/admin/tasks?status=all&user_id='.$uid, $tokens['support'])[1];
aqCheck($ts['total'] === 2 && count(array_filter($ts['items'], fn ($t) => $t['total'] !== null)) === 0, 'task owner filter and support amount redaction');
aqCheck(aqHttp('/admin/tasks?user_id[]=1', $admin)[0] === 400, 'task owner filter validated');
foreach (['support', 'operator'] as $role) {
    $t = aqHttp('/admin/tasks/'.$task, $tokens[$role])[1];
    aqCheck(! $t['can_view_finance'] && $t['task']['total'] === null && $t['invoice']['total'] === null && $t['instance']['recurring_price'] === null && $t['receipts'] === [] && ! isset($t['audit']['items'][0]['context']['amount'],$t['audit']['items'][0]['context']['payment_record_id']), $role.' task detail cannot bypass finance restriction');
    foreach (['invoices', 'receipts', 'transactions'] as $source)aqCheck(aqHttp('/admin/finance/'.$source.'?user_id='.$uid.'&instance_id='.$i, $tokens[$role])[0] === 403, $role.' cannot read '.$source);
}
foreach (['finance', 'super_admin'] as $role) {
    $t = aqHttp('/admin/tasks/'.$task, $tokens[$role])[1];
    aqCheck($t['can_view_finance'] && $t['invoice']['total'] === '10.00' && $t['receipts'] !== [] && $t['audit']['items'][0]['context']['amount'] === '9.99', $role.' retains financial task context');
    foreach (['invoices', 'receipts', 'transactions'] as $source) {
        $r = aqHttp('/admin/finance/'.$source.'?user_id='.$uid.'&instance_id='.$i, $tokens[$role])[1];
        aqCheck($r['total'] > 0 && count(array_filter($r['items'], fn ($v) => $v['user_id'] !== $uid)) === 0, $role.' scoped financial navigation '.$source);
    }
}
aqCheck(aqHttp('/admin/finance/invoices?instance_id='.$i.'&user_id='.$other, $admin)[1]['total'] === 0, 'finance instance navigation excludes malformed ownership');
aqCheck(aqHttp('/admin/finance/invoices?instance_id[]=1', $admin)[0] === 400, 'finance instance filter validates IDs');
$db->prepare("UPDATE admins SET role='support' WHERE id=?")->execute([$ids['finance']]);
aqCheck(aqHttp('/admin/customers', $tokens['finance'])[0] === 200 && aqHttp('/admin/finance/invoices', $tokens['finance'])[0] === 403 && ! aqHttp('/admin/tasks/'.$task, $tokens['finance'])[1]['can_view_finance'], 'existing token immediately obeys role downgrade');
$db->prepare("UPDATE admins SET status='disabled' WHERE id=?")->execute([$ids['operator']]);
aqCheck(aqHttp('/admin/customers', $tokens['operator'])[0] === 403 && aqHttp('/admin/instances/'.$i, $tokens['operator'])[0] === 403, 'disabled administrator rejected with existing token');
try {
    (new AdminQueryService())->customers($ids['operator'], []);
    aqCheck(false, 'disabled direct service');
} catch (App\Core\ApiException $e) {
    aqCheck($e->httpStatus === 403, 'service rechecks disabled admin independent of middleware');
}
aqCheck(aqSnapshot() === $before, 'all read queries and rejected writes preserve business and audit facts');
echo 'Admin query checks: '.$checks.PHP_EOL;
