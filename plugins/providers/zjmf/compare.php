<?php

declare(strict_types=1);

// Plugin-owned CLI assembly only. Does not boot Laravel, load .env, open a database or write a snapshot.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__, 3).'/vendor/autoload.php';

use App\Core\Money;
use App\Plugins\Providers\{ConfigOptions, PreflightResult};
use Plugins\providers\zjmf\{PriceComparison, ZjmfDriver};

try {
    if ($argc !== 2 || ! in_array($argv[1], ['--saved', '--live'], true)) {
        throw new RuntimeException('Expected --saved or --live');
    }
    $raw = stream_get_contents(STDIN, 2097153);
    if (strlen($raw) > 2097152) {
        throw new RuntimeException('Input too large');
    }
    $input = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    if (! is_array($input) || ! is_string($input['billing_cycle'] ?? null) || ! is_array($input['config_options'] ?? null)) {
        throw new RuntimeException('Invalid input');
    }
    $cycle = $input['billing_cycle'];
    $options = ConfigOptions::canonical($input['config_options']);
    $driver = new ZjmfDriver();
    $now = time();
    if ($argv[1] === '--live') {
        // Credentials enter via private stdin, never argv, a URL, a tracked file or the output.
        $connection = $driver->validateConnection($input['connection'] ?? []);
        $credentials = $connection['credentials'] + ['endpoint' => $connection['endpoint']];
        $pid = $input['remote_pid'] ?? null;
        if (! is_int($pid) || $pid < 1) {
            throw new RuntimeException('Invalid product');
        }
        $schema = $driver->getFormSchema($pid, $credentials);
        if (($schema['products']['id'] ?? null) !== $pid) {
            throw new RuntimeException('Mismatched product snapshot');
        }
        // Capture time belongs to this read, not to the subsequent online quotation.
        $snapshot = ['schema' => $schema, 'fetched_at' => time(), 'currency' => $input['currency'] ?? null];
        $now = time();
        $online = static fn (): PreflightResult => $driver->preflightPrice($pid, $cycle, $options, $credentials);
    } else {
        // An old capture can only be replayed at an explicitly supplied historical observation time.
        // It must never be silently stamped fresh or used to price a customer order.
        $now = $input['observed_at'] ?? $now;
        if (! is_int($now)) {
            throw new RuntimeException('Invalid observation time');
        }
        $snapshot = $input['snapshot'] ?? null;
        $online = static function () use ($input, $options, $cycle): PreflightResult {
            $r = $input['upstream_quote'] ?? [];
            $p = $r['products'] ?? [];
            if (($r['status'] ?? null) !== 200 || ($r['currency']['code'] ?? null) !== 'CNY'
                || ($p['billingcycle'] ?? null) !== $cycle || ! array_key_exists('type', $p) || $p['type'] !== false
                || ConfigOptions::canonical($input['upstream_config_options'] ?? []) !== $options
                || ! is_string($input['upstream_schema_hash'] ?? null)) {
                throw new RuntimeException('Invalid saved quotation');
            }

            return new PreflightResult(Money::positive($p['total'] ?? null), $options,
                context: ['schema_hash' => $input['upstream_schema_hash']], recurringAmount: Money::positive($p['signal_price'] ?? null));
        };
    }
    $result = (new PriceComparison())->compare($snapshot, $cycle, $options, $now, $online);
    echo json_encode(['mode' => $argv[1], 'observed_at' => $now] + $result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
    exit(in_array($result['status'], ['mismatch', 'rules_changed'], true) ? 2 : 0);
} catch (Throwable) {
    // Never print exception details: transport/schema input can contain provider credentials.
    fwrite(STDERR, "Price comparison failed; no price published or order created.\n");
    exit(1);
}
