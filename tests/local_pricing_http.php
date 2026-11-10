<?php

declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use App\Plugins\Providers\ConfigOptions;
use Plugins\providers\zjmf\{LocalPriceCalculator, PriceComparison, ZjmfDriver};

if (getenv('APP_ENV') !== 'test' || ! preg_match('~^http://127\.0\.0\.1:[0-9]+$~D', getenv('UPSTREAM_TEST_URL') ?: '')) {
    throw new RuntimeException('Isolated provider fixture required');
}
$checks = 0;
$url = getenv('UPSTREAM_TEST_URL');
function check(bool $ok, string $message): void
{
    global $checks;
    if (! $ok) {
        throw new RuntimeException($message);
    }$checks++;
    echo "PASS PRICE HTTP: $message\n";
}
function state(): array
{
    global $url;

    return json_decode(file_get_contents($url.'/__state'), true, 64, JSON_THROW_ON_ERROR);
}
function counts(): array
{
    global $url;

    return json_decode(file_get_contents($url.'/__request_counts'), true, 64, JSON_THROW_ON_ERROR);
}
function cli(array $input): array
{
    $pipes = [];
    $p = proc_open([PHP_BINARY, dirname(__DIR__).'/plugins/providers/zjmf/compare.php', '--live'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    return [proc_close($p), $out, $err];
}
$sample = json_decode(file_get_contents(__DIR__.'/fixtures/zjmf-pricing-samples.json'), true, 64, JSON_THROW_ON_ERROR)['samples'][0];
$config = $sample['cases'][0]['config_options'];
$input = ['remote_pid' => 389, 'billing_cycle' => 'monthly', 'currency' => ['id' => 1, 'code' => 'CNY'], 'config_options' => $config,
    'connection' => ['endpoint' => $url, 'credentials' => ['username' => 'fixture@example.test', 'password' => 'PRIVATE_HTTP_SENTINEL']]];
$before = state();
$beforeCounts = counts();
[$exit,$out,$err] = cli($input);
$r = json_decode($out, true, 64, JSON_THROW_ON_ERROR);
$after = counts();
check($exit === 0 && $r['status'] === 'matched' && $r['upstream']['first'] === '257.00', 'real CLI HTTP flow matches frozen quote oracle');
check(($after['/cart/get_total'] ?? 0) - ($beforeCounts['/cart/get_total'] ?? 0) === 1, 'comparison invokes online total exactly once');
check(! str_contains($out.$err, 'PRIVATE_HTTP_SENTINEL') && ! str_contains($out, '127.0.0.1'), 'output contains neither credentials nor provider endpoint');
file_get_contents($url.'/__config_mode?mode=pricing_mismatch');
[$exit,$out] = cli($input);
check($exit === 2 && json_decode($out, true)['status'] === 'mismatch', 'upstream recurring mismatch is diagnosed with failing exit status');
file_get_contents($url.'/__config_mode?mode=normal');
// Current fixture14 deliberately has no pricing matrix: retain full online quote rather than guess.
$driver = new ZjmfDriver();
$credentials = $input['connection']['credentials'] + ['endpoint' => $url];
$schema = $driver->getFormSchema(14, $credentials);
$options = ConfigOptions::defaults($driver->describeForm($schema));
$now = time();
$snapshot = ['schema' => $schema, 'fetched_at' => $now, 'currency' => ['id' => 1, 'code' => 'CNY']];
$r = (new PriceComparison())->compare($snapshot, 'monthly', $options, $now, fn () => $driver->preflightPrice(14, 'monthly', $options, $credentials));
check($r['status'] === 'online_only' && $r['upstream']['first'] === '16.00' && $r['upstream']['recurring'] === '14.00', 'incomplete live snapshot falls back to full online first and recurring quote');
// CLI failures must remain sanitized, including errors from a real HTTP request.
$input['remote_pid'] = 14;
$input['config_options'] = $options;
file_get_contents($url.'/__config_mode?mode=unavailable');
[$exit,$out,$err] = cli($input);
check($exit === 1 && $out === '' && ! str_contains($err, 'PRIVATE_HTTP_SENTINEL'), 'upstream unavailable never publishes a local price');
file_get_contents($url.'/__config_mode?mode=normal');
$afterState = state();
unset($before['request_counts'],$afterState['request_counts']);
check($before === $afterState, 'comparison performs no create, payment, renewal, power or other upstream mutation');
echo "Local pricing HTTP checks: $checks\n";
