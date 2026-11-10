<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use App\Core\{ApiException,Database,Security};
use App\Services\{AdminAuthService,AuthService,BillingService,DemoService,TicketService};

set_exception_handler(static function (Throwable $e): never {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");
    exit(1);
});
if (config('app.env') !== 'test' || config('database.connections.mysql.database') !== 'jianyun_test') {
    throw new RuntimeException('Isolated database required');
}
$db = Database::getInstance();
$service = new TicketService();
$checks = 0;
function tkOp(array $v): array
{
    global $service;

    return $v['op'] === 'create' ? $service->create($v['actor'], $v['body']) : $service->write($v['actor'], $v['admin'] ?? false, $v['id'], $v['body'], $v['op'] === 'close');
}
if (($argv[1] ?? '') === '--child') {
    $v = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
    echo 'READY:'.$db->query('SELECT CONNECTION_ID()')->fetchColumn()."\n";
    fflush(STDOUT);
    try {
        echo json_encode(['status' => 200, 'data' => tkOp($v)], JSON_THROW_ON_ERROR);
    } catch (ApiException $e) {
        echo json_encode(['status' => $e->httpStatus]);
    }exit;
}
function tkCheck(bool $ok, string $label): void
{
    global $checks;
    if (! $ok)throw new RuntimeException('FAIL ticket: '.$label);
    $checks++;
    echo 'PASS ticket: '.$label."\n";
}
function tkKey(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
    $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
    $h = bin2hex($bytes);

    return substr($h, 0, 8).'-'.substr($h, 8, 4).'-'.substr($h, 12, 4).'-'.substr($h, 16, 4).'-'.substr($h, 20);
}
function tkBody(string $content = 'TICKET-CUSTOMER: a public message'): array
{
    return ['content' => $content, 'request_key' => tkKey()];
}
function tkCreate(?int $instance = null): array
{
    return ['title' => 'TICKET: UTF-8 客户问题', 'instance_id' => $instance] + tkBody();
}
function tkReject(callable $f, int $status, string $label): void
{
    try {
        $f();
    } catch (ApiException $e) {
        tkCheck($e->httpStatus === $status, $label);

        return;
    }throw new RuntimeException('Missing rejection: '.$label);
}
function tkValue(string $sql, array $args = []): mixed
{
    global $db;
    $s = $db->prepare($sql);
    $s->execute($args);

    return $s->fetchColumn();
}
function tkHttp(string $path, ?string $token = null, string $method = 'GET', ?array $body = null): array
{
    $c = curl_init(getenv('TEST_BASE_URL').'/api/v1'.$path);
    $cache = '';
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => array_filter(['Content-Type: application/json', $token ? 'Authorization: Bearer '.$token : null]), CURLOPT_HEADERFUNCTION => static function ($c, $line) use (&$cache) {
        if (str_starts_with(strtolower($line), 'cache-control:'))$cache = trim(substr($line, 14));

        return strlen($line);
    }]);
    if ($body !== null)curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    $raw = curl_exec($c);
    $status = curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);

    return [$status, json_decode($raw, true, 64, JSON_THROW_ON_ERROR)['data'] ?? null, $cache];
}
function tkSpawn(array $v): array
{
    $p = proc_open([PHP_BINARY, __FILE__, '--child'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__));
    fwrite($pipes[0], json_encode($v, JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    stream_set_timeout($pipes[1], 10);
    $line = trim(fgets($pipes[1]) ?: '');
    if (! preg_match('/^READY:([0-9]+)$/D', $line, $m))throw new RuntimeException('Child not ready');

    return [$p, $pipes, (int) $m[1]];
}
function tkWait(array $c): void
{
    $end = microtime(true) + 8;
    do {
        if (tkValue('SELECT trx_state FROM information_schema.innodb_trx WHERE trx_mysql_thread_id=?', [$c[2]]) === 'LOCK WAIT')return;
        usleep(100000);
    } while (microtime(true) < $end);
    throw new RuntimeException('Child did not contend on held ticket/actor');
}
function tkDone(array $c): array
{
    [$p,$pipes] = $c;
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($p) !== 0)throw new RuntimeException('Child failed: '.$err.$out);

    return json_decode($out, true, 64, JSON_THROW_ON_ERROR);
}
function tkLock(int $id): void
{
    global $db;
    $s = $db->prepare('SELECT id FROM tickets WHERE id=? FOR UPDATE');
    $s->execute([$id]);
    $s->fetch();
}
function tkFacts(array $tables): array
{
    global $db;
    $out = [];
    foreach ($tables as $t)$out[$t] = $db->query('SELECT * FROM '.$t.' ORDER BY id')->fetchAll();

    return $out;
}
$auth = new AuthService();
$a = $auth->register(['email' => 'ticket-a@example.test', 'password' => 'TicketCustomer!2026']);
$b = $auth->register(['email' => 'ticket-b@example.test', 'password' => 'TicketCustomer!2026']);
$uid = (int) $a['user']['id'];
$other = (int) $b['user']['id'];
$ids = [];
$tokens = [];
foreach (['super_admin', 'finance', 'support', 'operator'] as $role) {
    $ids[$role] = (new AdminAuthService())->create(['username' => 'ticket-'.$role, 'email' => 'ticket-'.$role.'@example.test', 'password' => 'TicketAdmin!2026', 'role' => $role]);
    $tokens[$role] = Security::generateToken(['uid' => $ids[$role], 'sub_type' => 'admin']);
}$admin = $ids['support'];
$at = $tokens['support'];
// Upgrade legacy rows in this disposable DB, then run the migration twice for interrupted-install recovery.
$db->prepare('INSERT INTO tickets(user_id,title) VALUES(?,?)')->execute([$uid, 'Legacy preserved']);
$legacy = (int) $db->lastInsertId();
$db->prepare('INSERT INTO ticket_replies(ticket_id,user_id,content) VALUES(?,?,?)')->execute([$legacy, $uid, 'LEGACY: unchanged']);
$legacyReply = (int) $db->lastInsertId();
$db->exec('ALTER TABLE ticket_replies ADD INDEX legacy_user_fk(user_id), ADD INDEX legacy_admin_fk(admin_id)');
$db->exec('ALTER TABLE ticket_replies DROP INDEX uk_ticket_user_request, DROP INDEX uk_ticket_admin_request, DROP COLUMN request_key, DROP COLUMN request_hash');
$migration = require dirname(__DIR__).'/database/migrations/2026_11_09_000009_ticket_requests.php';
$migration->up();
$migration->up();
tkCheck(tkValue('SELECT content FROM ticket_replies WHERE id=?', [$legacyReply]) === 'LEGACY: unchanged' && tkValue('SELECT request_key FROM ticket_replies WHERE id=?', [$legacyReply]) === null, 'upgrade retains legacy text and nullable keys, repeat migration safe');
tkCheck(count($db->query('SHOW TABLES')->fetchAll()) === 27, 'no new tables');
$pid = (new DemoService())->seed();
$billing = new BillingService();
$in = $billing->checkout($uid, ['product_id' => $pid, 'hostname' => 'TicketLocal', 'password' => 'TicketRoot!2026', 'billing_cycle' => 'monthly']);
$instance = (int) $in['instance_id'];
$foreign = $billing->checkout($other, ['product_id' => $pid, 'hostname' => 'TicketOther', 'password' => 'TicketRoot!2026', 'billing_cycle' => 'monthly'])['instance_id'];
$before = tkFacts(['users', 'instances', 'invoices', 'payment_records', 'transactions', 'tasks', 'products', 'providers', 'commissions', 'notification_deliveries']);
foreach ([null, $tokens['support'], Security::generateToken(['uid' => $uid], -1)] as $token)tkCheck(tkHttp('/tickets', $token)[0] === 401, 'anonymous/admin/expired token rejected from customer tickets');
tkCheck(tkHttp('/admin/tickets', $a['token'])[0] === 401 && tkHttp('/admin/tickets')[0] === 401, 'customer/anonymous rejected from admin tickets');
foreach (['/tickets', '/tickets/instances'] as $path) {
    [$code,,$cache] = tkHttp($path, $a['token']);
    tkCheck($code === 200 && str_contains($cache, 'no-store'), 'customer read no-store: '.$path);
}
foreach ($tokens as $role => $token)tkCheck(tkHttp('/admin/tickets', $token)[0] === 200, $role.' can query tickets');
$impersonated = Security::generateToken(['uid' => $uid, 'is_impersonated' => true, 'operator_admin_id' => $admin]);
tkCheck(tkHttp('/tickets', $impersonated, 'POST', tkCreate())[0] === 403, 'impersonated session cannot pose as customer writer');
$input = tkCreate($instance);
[$code,$made] = tkHttp('/tickets', $a['token'], 'POST', $input);
$id = $made['ticket_id'];
tkCheck($code === 200 && ! $made['replayed'], 'HTTP create commits ticket and first reply');
$repeat = $service->create($uid, $input);
tkCheck($repeat['ticket_id'] === $id && $repeat['reply_id'] === $made['reply_id'] && $repeat['replayed'], 'duplicate creation returns original identity');
tkReject(fn () => $service->create($uid, array_replace($input, ['title' => 'Changed'])), 409, 'same creation key changed title rejected');
tkReject(fn () => $service->create($uid, tkCreate((int) $foreign)), 404, 'cannot link another customer instance');
tkReject(fn () => $service->create($uid, tkCreate(9999999)), 404, 'cannot link missing instance');
$choice = $service->instances($uid, ['search' => 'TicketLocal']);
tkCheck($choice['total'] === 1 && $choice['items'][0]['id'] === $instance && ! isset($choice['items'][0]['encrypted_password']), 'instance selector scoped and safe');
tkCheck($service->instances($uid, ['search' => (string) $foreign])['total'] === 0, 'selector hides foreign instance');
$detail = $service->detail($uid, false, $id);
tkCheck($detail['ticket']['status'] === 'open' && $detail['instance']['id'] === $instance && $detail['replies']['total'] === 1, 'detail includes original instance and first public message');
$raw = json_encode($detail);
tkCheck(! str_contains($raw, 'request_key') && ! str_contains($raw, 'request_hash') && ! str_contains($raw, 'password') && ! isset($detail['ticket']['customer']), 'client detail omits keys, passwords and extra identities');
foreach (['GET', 'POST'] as $method) {
    $path = '/tickets/'.$id.($method === 'POST' ? '/replies' : '');
    tkCheck(tkHttp($path, $b['token'], $method, $method === 'POST' ? tkBody() : null)[0] === 404, 'foreign customer rejected: '.$method);
}
tkCheck(tkHttp('/tickets/'.$id.'/replies', $impersonated, 'POST', tkBody())[0] === 403, 'impersonated reply must use administrator identity');
tkCheck(tkHttp('/tickets/'.$id.'/close', $b['token'], 'POST', ['request_key' => tkKey(), 'expected_version' => $detail['version']])[0] === 404, 'foreign customer cannot close');
foreach ([['title' => ' '], ['title' => str_repeat('题', 151)], ['title' => "line\nbreak"], ['content' => ' '], ['content' => str_repeat('文', 10001)], ['content' => "bad\0text"], ['request_key' => 'not-uuid'], ['status' => 'closed'], ['user_id' => $other], ['attachments' => []], ['type' => 'internal_note']] as $bad) {
    tkReject(fn () => $service->create($uid, array_replace(tkCreate(), $bad)), 400, 'malformed or unsupported creation: '.array_key_first($bad));
}
$max = $service->create($uid, array_replace(tkCreate(), ['title' => str_repeat('题', 150), 'content' => str_repeat('文', 10000)]));
tkCheck($max['ticket_id'] > 0, 'unassociated ticket accepts maximum UTF-8 character lengths');
$xss = '<img src=x onerror="window.TICKET_XSS=1"> <script>alert(1)</script> https://example.test';
$reply = tkBody($xss);
$sent = $service->write($admin, true, $id, $reply);
$again = $service->write($admin, true, $id, $reply);
tkCheck($again['replayed'] && $sent['reply_id'] === $again['reply_id'], 'admin reply replay is unique');
$d = $service->detail($uid, false, $id);
tkCheck($d['ticket']['status'] === 'answered' && $d['replies']['items'][1]['content'] === $xss, 'HTML stays literal text and reply status accurate');
tkCheck((int) tkValue("SELECT COUNT(*) FROM admin_audit_logs WHERE action='ticket.reply' AND target_id=?", [$id]) === 1, 'duplicate admin reply audits once');
tkReject(fn () => $service->write($admin, true, $id, array_replace($reply, ['content' => 'different'])), 409, 'same reply key different content rejected');
tkReject(fn () => $service->write($admin, true, $max['ticket_id'], $reply), 409, 'same reply key cannot target another thread');
$customerReply = tkBody('CUSTOMER-FOLLOWUP');
$service->write($uid, false, $id, $customerReply);
tkCheck($service->detail($uid, false, $id)['ticket']['status'] === 'customer_replied', 'customer follow-up sets pending customer response state');
$visible = $service->detail($uid, false, $id);
$version = $visible['version'];
foreach (['internal_note', 'transfer_log', 'reply'] as $type) {
    $db->prepare('INSERT INTO ticket_replies(ticket_id,user_id,admin_id,type,content) VALUES(?,?,?,?,?)')->execute([$id, $type === 'reply' ? $other : null, $type === 'reply' ? null : $admin, $type, 'TICKET_PRIVATE_NEVER_PUBLIC']);
}
$clean = $service->detail($uid, false, $id);
tkCheck($clean['replies']['total'] === $visible['replies']['total'] && $clean['version'] === $version && ! str_contains(json_encode($clean), 'TICKET_PRIVATE'), 'private/transfers/malformed ownership excluded from content, count and close version');
tkCheck(! str_contains(json_encode($service->detail($admin, true, $id)), 'TICKET_PRIVATE'), 'minimal admin page also only exposes public messages');
for ($j = 0; $j < 22; $j++)$service->write($uid, false, $id, tkBody('page '.$j));
$p1 = $service->detail($uid, false, $id, ['per_page' => 5]);
$p2 = $service->detail($uid, false, $id, ['per_page' => 5, 'page' => 2]);
$last = $service->detail($uid, false, $id, ['per_page' => 5, 'page' => 'last']);
tkCheck($p1['replies']['items'][4]['id'] < $p2['replies']['items'][0]['id'] && count($last['replies']['items']) > 0, 'ordered replies paginate without duplicates, last page supported');
$filtered = $service->listing($admin, true, ['email' => 'ticket-a@example.test', 'instance_id' => $instance]);
tkCheck($filtered['total'] === 1 && $filtered['items'][0]['id'] === $id, 'email to original instance and ticket lookup');
tkCheck($service->listing($other, false, [])['total'] === 0 && $service->listing($admin, true, ['user_id' => $other])['total'] === 0, 'customer and admin empty filter states');
foreach ([['status' => []], ['status' => 'bad'], ['page' => 0], ['per_page' => 101], ['page' => 100001], ['email' => []], ['instance_id' => []]] as $q)tkReject(fn () => $service->listing($admin, true, $q), 400, 'invalid filter: '.array_key_first($q));
// Audit failure must roll back both message and status.
$snapshot = tkFacts(['tickets', 'ticket_replies', 'admin_audit_logs']);
$db->exec("CREATE TRIGGER ticket_audit_failure BEFORE INSERT ON admin_audit_logs FOR EACH ROW BEGIN IF NEW.action IN ('ticket.reply','ticket.close') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated ticket audit fault'; END IF; END");
try {
    foreach ([false, true] as $close) {
        $body = $close ? ['request_key' => tkKey(), 'expected_version' => $service->detail($admin, true, $id)['version']] : tkBody();
        try {
            $service->write($admin, true, $id, $body, $close);
            throw new RuntimeException('Missing audit fault');
        } catch (PDOException) {
        }tkCheck(tkFacts(['tickets', 'ticket_replies', 'admin_audit_logs']) === $snapshot, 'audit fault rolls back message/status/close: '.(int) $close);
    }
} finally {
    $db->exec('DROP TRIGGER ticket_audit_failure');
}
$stale = $service->detail($uid, false, $id)['version'];
$service->write($admin, true, $id, tkBody());
tkReject(fn () => $service->write($uid, false, $id, ['request_key' => tkKey(), 'expected_version' => $stale], true), 409, 'close rejects unread new reply');
$close = ['request_key' => tkKey(), 'expected_version' => $service->detail($uid, false, $id)['version']];
$ended = $service->write($uid, false, $id, $close, true);
tkCheck($service->write($uid, false, $id, $close, true)['reply_id'] === $ended['reply_id'], 'repeat close reuses original record');
tkCheck($service->detail($uid, false, $id)['ticket']['closed_at'] !== null, 'close timestamp and public closure record committed');
tkCheck($service->create($uid, $input)['ticket_id'] === $id && $service->write($admin, true, $id, $reply)['reply_id'] === $sent['reply_id'], 'successful historical writes replay after closure');
tkReject(fn () => $service->write($uid, false, $id, tkBody()), 409, 'closed thread cannot receive new replies');
tkReject(fn () => $service->write($admin, true, $id, ['request_key' => tkKey(), 'expected_version' => $close['expected_version']], true), 409, 'new close key cannot close twice');
// Deterministic two-connection races: reply before close must invalidate the CURRENT-read version.
foreach (['reply_first', 'close_first'] as $order) {
    $thread = $service->create($uid, tkCreate())['ticket_id'];
    $version = $service->detail($uid, false, $thread)['version'];
    $closeBody = ['request_key' => tkKey(), 'expected_version' => $version];
    $child = null;
    Database::transaction(function () use ($thread, $order, $uid, $admin, $service, $closeBody, &$child) {
        tkLock($thread);
        $child = tkSpawn($order === 'reply_first' ? ['op' => 'close', 'actor' => $admin, 'admin' => true, 'id' => $thread, 'body' => $closeBody] : ['op' => 'reply', 'actor' => $admin, 'admin' => true, 'id' => $thread, 'body' => tkBody()]);
        tkWait($child);
        $service->write($uid, false, $thread, $order === 'reply_first' ? tkBody('RACE NEW CUSTOMER MESSAGE') : $closeBody, $order === 'close_first');
    });
    $r = tkDone($child);
    $d = $service->detail($uid, false, $thread);
    tkCheck($r['status'] === 409 && $d['ticket']['status'] === ($order === 'reply_first' ? 'customer_replied' : 'closed'), 'reply/close lock race protects messages: '.$order);
}
$thread = $service->create($uid, tkCreate())['ticket_id'];
$child = null;
Database::transaction(function () use ($thread, $admin, $uid, $service, &$child) {
    tkLock($thread);
    $child = tkSpawn(['op' => 'reply', 'actor' => $admin, 'admin' => true, 'id' => $thread, 'body' => tkBody('ADMIN PARALLEL')]);
    tkWait($child);
    $service->write($uid, false, $thread, tkBody('CUSTOMER PARALLEL'));
});
tkCheck(tkDone($child)['status'] === 200 && $service->detail($uid, false, $thread)['replies']['total'] === 3, 'concurrent distinct replies both retained');
$body = tkCreate();
$children = [tkSpawn(['op' => 'create', 'actor' => $uid, 'body' => $body]), tkSpawn(['op' => 'create', 'actor' => $uid, 'body' => $body])];
$x = tkDone($children[0]);
$y = tkDone($children[1]);
tkCheck($x['status'] === 200 && $y['status'] === 200 && $x['data']['ticket_id'] === $y['data']['ticket_id'] && $x['data']['reply_id'] === $y['data']['reply_id'], 'simultaneous duplicate creation persists one thread and first message');
$body = tkBody('SIMULTANEOUS SAME MESSAGE');
$children = [tkSpawn(['op' => 'reply', 'actor' => $uid, 'id' => $thread, 'body' => $body]), tkSpawn(['op' => 'reply', 'actor' => $uid, 'id' => $thread, 'body' => $body])];
$x = tkDone($children[0]);
$y = tkDone($children[1]);
tkCheck($x['status'] === 200 && $y['status'] === 200 && $x['data']['reply_id'] === $y['data']['reply_id'], 'simultaneous duplicate replies persist once');
$db->prepare('UPDATE tickets SET instance_id=? WHERE id=?')->execute([$foreign, $thread]);
tkCheck($service->detail($uid, false, $thread)['instance'] === null && array_values(array_filter($service->listing($uid, false, [])['items'], fn ($r) => $r['id'] === $thread))[0]['instance_id'] === null, 'malformed instance binding not exposed');
$db->prepare("UPDATE admins SET status='disabled' WHERE id=?")->execute([$admin]);
tkCheck(tkHttp('/admin/tickets', $at)[0] === 403, 'disabled admin rejected with old token');
tkReject(fn () => $service->write($admin, true, $thread, tkBody()), 403, 'direct write rechecks active admin');
$db->prepare("UPDATE admins SET status='active' WHERE id=?")->execute([$admin]);
$db->prepare("UPDATE users SET status='suspended' WHERE id=?")->execute([$uid]);
tkCheck(tkHttp('/tickets', $a['token'])[0] === 403, 'suspended customer rejected');
tkReject(fn () => $service->create($uid, tkCreate()), 403, 'direct create rechecks current customer status');
$db->prepare("UPDATE users SET status='active',updated_at=? WHERE id=?")->execute([array_values(array_filter($before['users'], fn ($u) => (int) $u['id'] === $uid))[0]['updated_at'], $uid]);
$adminClosed = $service->write($admin, true, $thread, ['request_key' => tkKey(), 'expected_version' => $service->detail($admin, true, $thread)['version']], true);
tkCheck((int) tkValue("SELECT COUNT(*) FROM admin_audit_logs WHERE action='ticket.close' AND target_id=?", [$thread]) === 1 && $adminClosed['reply_id'] > 0, 'admin closure has one public record and same-transaction audit');
tkCheck(tkFacts(array_keys($before)) === $before, 'communication preserves users, resources, finance, tasks, catalog, providers and notifications');
echo 'Ticket checks: '.$checks."\n";
