<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use App\Core\Security;

$server = $_SERVER;
$trusted = config('jianyun.trusted_proxies');
$checks = 0;
function ipCase(string $label, string $proxies, mixed $peer, mixed $xff, mixed $cf, string $expected): void
{
    global $checks;
    config(['jianyun.trusted_proxies' => $proxies]);
    $_SERVER['REMOTE_ADDR'] = $peer;
    $_SERVER['HTTP_X_FORWARDED_FOR'] = $xff;
    $_SERVER['HTTP_CF_CONNECTING_IP'] = $cf;
    if (Security::getClientIp() !== $expected) {
        throw new RuntimeException('FAIL: '.$label);
    }
    $checks++;
    echo "PASS: {$label}\n";
}
try {
    ipCase('unconfigured proxies ignore forged headers', '', '192.0.2.10', '203.0.113.1', '203.0.113.2', '192.0.2.10');
    ipCase('untrusted peer ignores forwarded headers', '127.0.0.1', '192.0.2.10', '203.0.113.1', '', '192.0.2.10');
    ipCase('trusted peer accepts valid IPv4', '127.0.0.1', '127.0.0.1', '203.0.113.1', '', '203.0.113.1');
    ipCase('do not accept CF header without normalized XFF', '127.0.0.1', '127.0.0.1', '', '203.0.113.2', '127.0.0.1');
    ipCase('ignore spoofed left prefix at first untrusted hop', '127.0.0.1', '127.0.0.1', '198.51.100.1, 203.0.113.5', '', '203.0.113.5');
    ipCase('multiple trusted proxies and IPv4 CIDR', '127.0.0.1,10.0.0.0/24', '127.0.0.1', '203.0.113.1, 10.0.0.8', '', '203.0.113.1');
    ipCase('CIDR boundary excludes neighboring network', '10.0.0.0/24', '10.0.1.1', '203.0.113.1', '', '10.0.1.1');
    ipCase('partial-byte IPv4 CIDR', '192.0.2.128/25', '192.0.2.254', '203.0.113.1', '', '203.0.113.1');
    ipCase('IPv6 exact proxy and client', '::1', '::1', '2001:db8::5', '', '2001:db8::5');
    ipCase('IPv6 proxy CIDR', '2001:db8:1::/48', '2001:db8:1::9', '2001:db8:2::3', '', '2001:db8:2::3');
    ipCase('malformed XFF falls back to peer', '127.0.0.1', '127.0.0.1', 'garbage, 203.0.113.5', '', '127.0.0.1');
    ipCase('oversized XFF falls back to peer', '127.0.0.1', '127.0.0.1', str_repeat('x', 9000), '', '127.0.0.1');
    ipCase('invalid proxy config fails closed', '127.0.0.1/33,invalid,::1/129', '127.0.0.1', '203.0.113.1', '', '127.0.0.1');
    ipCase('invalid peer returns valid placeholder IP', '127.0.0.1', 'not-an-ip', '203.0.113.1', '', '0.0.0.0');
    ipCase('array header falls back safely', '127.0.0.1', '127.0.0.1', ['203.0.113.1'], '', '127.0.0.1');
    foreach ([
        [['remote_instance_id' => 'host:sample', 'billing_cycle' => 'onetime'], 'not_applicable', 'one-time delivered service has no expiring entitlement'],
        [['due_date' => '2020-01-01 00:00:00'], 'not_started', 'unbound resource never claims delivered entitlement'],
        [['remote_instance_id' => 'host:sample', 'status' => 'suspended', 'due_date' => gmdate('Y-m-d H:i:s', time() + 86400)], 'valid', 'local entitlement is independent of service suspension'],
    ] as [$instance,$expected,$label]) {
        if (\App\Services\InstanceServiceStatusService::summary($instance)['entitlement_state'] !== $expected) {
            throw new RuntimeException('FAIL: '.$label);
        }
        $checks++;
        echo "PASS: {$label}\n";
    }
    echo "{$checks} unit checks passed.\n";
} finally {
    $_SERVER = $server;
    config(['jianyun.trusted_proxies' => $trusted]);
}
