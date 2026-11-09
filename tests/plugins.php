<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

use App\Plugins\Captcha\{CaptchaDriverInterface, CaptchaDriverRegistry, CaptchaVerifyResult};

$checks = 0;
function pluginCheck(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    $checks++;
    echo "PASS: {$label}\n";
}
function pluginRejects(callable $callback, string $label): void
{
    try {
        $callback();
    } catch (RuntimeException | JsonException | InvalidArgumentException) {
        pluginCheck(true, $label);
        return;
    }
    pluginCheck(false, $label);
}

$installed = CaptchaDriverRegistry::installed();
pluginCheck($installed->get('builtin_image')->getType() === 'image', 'installed manifest resolves the builtin driver');
pluginRejects(static fn() => $installed->get('turnstile'), 'uninstalled drivers fail closed');
$external = new class implements CaptchaDriverInterface {
    public function getDriverId(): string { return 'external_test'; }
    public function getName(): string { return 'External test'; }
    public function getType(): string { return 'click'; }
    public function getConfigSchema(): array { return []; }
    public function generateChallenge(string $scene, string $clientIp): array { return ['challenge_id' => $scene]; }
    public function verify(array $payload, string $clientIp): CaptchaVerifyResult { return new CaptchaVerifyResult(true); }
};
$registry = new CaptchaDriverRegistry(['external_test' => static fn() => $external]);
pluginCheck($registry->get('external_test') === $external, 'registry accepts another interface implementation without core edits');
pluginRejects(static fn() => (new CaptchaDriverRegistry(['wrong_id' => static fn() => $external]))->get('wrong_id'), 'driver identity mismatch fails closed');
pluginRejects(static fn() => (new CaptchaDriverRegistry(['invalid' => static fn() => new stdClass()]))->get('invalid'), 'non-interface entries fail closed');

$directory = sys_get_temp_dir() . '/jianyun-plugin-' . bin2hex(random_bytes(8));
mkdir($directory . '/test', 0700, true);
try {
    foreach ([
        ['id' => 'other', 'type' => 'captcha', 'entry' => 'TestDriver.php'],
        ['id' => 'test', 'type' => 'captcha', 'entry' => '../BuiltinImageDriver.php'],
        ['id' => 'test', 'type' => 'provider', 'entry' => 'TestDriver.php'],
        ['id' => 'test', 'type' => 'captcha', 'entry' => 'MissingDriver.php'],
    ] as $manifest) {
        file_put_contents($directory . '/test/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        pluginRejects(static fn() => CaptchaDriverRegistry::fromDirectory($directory), 'manifest rejects mismatched identity, traversal, type or missing entry: ' . $manifest['entry']);
    }
    file_put_contents($directory . '/test/manifest.json', '{');
    pluginRejects(static fn() => CaptchaDriverRegistry::fromDirectory($directory), 'malformed manifest fails closed');
    file_put_contents($directory . '/test/manifest.json', json_encode(['id' => 'test', 'type' => 'captcha', 'entry' => 'ExternalDriver.php'], JSON_THROW_ON_ERROR));
    file_put_contents($directory . '/test/ExternalDriver.php', <<<'PHP'
<?php
namespace Plugins\captcha\test;
final class ExternalDriver implements \App\Plugins\Captcha\CaptchaDriverInterface {
    public function getDriverId(): string { return 'test'; }
    public function getName(): string { return 'Manifest test'; }
    public function getType(): string { return 'click'; }
    public function getConfigSchema(): array { return []; }
    public function generateChallenge(string $scene, string $clientIp): array { return ['scene' => $scene]; }
    public function verify(array $payload, string $clientIp): \App\Plugins\Captcha\CaptchaVerifyResult { return new \App\Plugins\Captcha\CaptchaVerifyResult(true); }
}
PHP);
    $discovered = CaptchaDriverRegistry::fromDirectory($directory)->get('test');
    pluginCheck($discovered->getType() === 'click' && $discovered->generateChallenge('register', '')['scene'] === 'register', 'a new manifest loads and dispatches its own driver without core registration');
    file_put_contents($directory . '/OutsideDriver.php', '<?php $GLOBALS["outside_plugin_loaded"] = true;');
    symlink($directory . '/OutsideDriver.php', $directory . '/test/OutsideDriver.php');
    file_put_contents($directory . '/test/manifest.json', json_encode(['id' => 'test', 'type' => 'captcha', 'entry' => 'OutsideDriver.php'], JSON_THROW_ON_ERROR));
    pluginRejects(static fn() => CaptchaDriverRegistry::fromDirectory($directory), 'entry symlink escape is rejected before PHP is loaded');
    pluginCheck(!isset($GLOBALS['outside_plugin_loaded']), 'rejected external entry has no executed side effects');
    unlink($directory . '/test/OutsideDriver.php');
    unlink($directory . '/OutsideDriver.php');

    mkdir($directory . '/external', 0700);
    file_put_contents($directory . '/external/manifest.json', json_encode(['id' => 'escape', 'type' => 'captcha', 'entry' => 'EscapeDriver.php'], JSON_THROW_ON_ERROR));
    symlink($directory . '/external', $directory . '/escape');
    pluginRejects(static fn() => CaptchaDriverRegistry::fromDirectory($directory), 'plugin directory symlink escape is rejected');
    unlink($directory . '/escape');
    unlink($directory . '/external/manifest.json');
    rmdir($directory . '/external');
} finally {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        if ($entry->isDir() && !$entry->isLink()) { rmdir($entry->getPathname()); }
        else { unlink($entry->getPathname()); }
    }
    rmdir($directory);
}

// 持续守住核心业务对具体插件的依赖边界，插件实现只能通过装配层接入。
$files = [dirname(__DIR__) . '/public/index.php'];
foreach (['Services', 'Middlewares', 'Http', 'Controllers', 'Models'] as $layer) {
    $path = dirname(__DIR__) . '/app/' . $layer;
    if (!is_dir($path)) { continue; }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php') { $files[] = $file->getPathname(); }
    }
}
foreach ($files as $file) {
    foreach (token_get_all(file_get_contents($file)) as $token) {
        if (is_array($token) && ((in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
            && str_starts_with(strtolower(ltrim($token[1], '\\')), 'plugins\\'))
            || ($token[0] === T_STRING && strtolower($token[1]) === 'plugins'))) {
            throw new RuntimeException('Core business layer depends on a concrete plugin: ' . $file);
        }
    }
}
pluginCheck(true, 'business services, middlewares and HTTP entry depend on plugin contracts only');


pluginCheck(\App\Plugins\Providers\ProviderRegistry::installed()->get('zjmf')->getQueryAuthority() === 'EVENTUAL_CONSISTENCY', 'provider manifest resolves without core concrete dependency');
pluginCheck(\App\Plugins\Providers\ProviderRegistry::installed()->get('manual')->create([], [])->code === 'NON_QUERYABLE', 'manual driver never pretends external delivery succeeded');
pluginCheck(\App\Plugins\Providers\ProviderRegistry::installed()->get('zjmf') instanceof \App\Plugins\Providers\RenewalDriverInterface
    && !(\App\Plugins\Providers\ProviderRegistry::installed()->get('manual') instanceof \App\Plugins\Providers\RenewalDriverInterface), 'renewal is an explicit optional capability, never implied by create support');
$gateway = \App\Plugins\Payments\PaymentRegistry::installed()->get('epay');
pluginCheck($gateway->getDriverId() === 'epay', 'payment manifest resolves its interface');
$root = sys_get_temp_dir() . '/jianyun-loader-' . bin2hex(random_bytes(8));
mkdir($root . '/providers/escape', 0700, true);
try {
    file_put_contents($root . '/OutsideDriver.php', '<?php $GLOBALS["generic_plugin_escape"] = true;');
    file_put_contents($root . '/providers/escape/manifest.json', json_encode(['id'=>'escape','type'=>'provider','entry'=>'OutsideDriver.php']));
    symlink($root . '/OutsideDriver.php', $root . '/providers/escape/OutsideDriver.php');
    pluginRejects(static fn() => \App\Plugins\PluginLoader::load($root . '/providers', 'provider', \App\Plugins\Providers\UpstreamDriverInterface::class), 'generic plugin loader rejects entry symlink before execution');
    pluginCheck(!isset($GLOBALS['generic_plugin_escape']), 'rejected provider entry has no side effects');
    symlink($root . '/providers', $root . '/linked');
    pluginRejects(static fn() => \App\Plugins\PluginLoader::load($root . '/linked', 'provider', \App\Plugins\Providers\UpstreamDriverInterface::class), 'generic plugin root cannot be a symlink');
    unlink($root . '/linked');
    unlink($root . '/providers/escape/OutsideDriver.php');
    symlink($root . '/OutsideDriver.php', $root . '/providers/escape/manifest-link.json');
    unlink($root . '/providers/escape/manifest.json');
    rename($root . '/providers/escape/manifest-link.json', $root . '/providers/escape/manifest.json');
    pluginRejects(static fn() => \App\Plugins\PluginLoader::load($root . '/providers', 'provider', \App\Plugins\Providers\UpstreamDriverInterface::class), 'generic plugin manifest cannot escape physical directory');
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) { rmdir($entry->getPathname()); } else { unlink($entry->getPathname()); }
    }
    rmdir($root);
}
$passwordPolicy = \App\Plugins\Providers\PasswordPolicy::class;
pluginCheck($passwordPolicy::bounds([]) === ['min_length'=>12,'max_length'=>72], 'drivers without a password policy retain existing limits');
$passwordPolicy::validate(str_repeat('A',72), []);
$policy = ['password_policy'=>['min_length'=>12,'max_length'=>20]];
$passwordPolicy::validate(str_repeat('A',12), $policy);
$passwordPolicy::validate(str_repeat('A',20), $policy);
pluginCheck(true, 'password policy accepts both inclusive boundaries');
pluginRejects(static fn() => $passwordPolicy::validate(str_repeat('A',21), $policy), 'password policy rejects 21 bytes');
pluginRejects(static fn() => $passwordPolicy::validate(str_repeat('密',7), $policy), 'password limit measures UTF-8 bytes like upstream');
pluginRejects(static fn() => $passwordPolicy::bounds(['password_policy'=>['min_length'=>12,'max_length'=>'20']]), 'malformed policy fails closed');
$driver=\App\Plugins\Providers\ProviderRegistry::installed()->get('zjmf');
$contextMethod=new ReflectionMethod($driver,'inputContext');$nameMethod=new ReflectionMethod($driver,'hostname');
$schema=json_decode(file_get_contents(__DIR__.'/fixtures/zjmf-product-14.json'),true,64,JSON_THROW_ON_ERROR);
$context=$contextMethod->invoke($driver,$schema);
$identity=['order_namespace'=>'isolated-policy-test','idempotency_key'=>'instance_create:inv_1'];
$name=$nameMethod->invoke($driver,$identity+['driver_context'=>$context]);
pluginCheck(preg_match('/^ser[0-9]{12}$/D',$name)===1 && $name===$nameMethod->invoke($driver,$identity+['driver_context'=>$context]), 'schema hostname generation remains deterministic for lost-response recovery');
$oldName=$nameMethod->invoke($driver,$identity+['driver_context'=>['hostname_rule'=>'alnum8']]);
pluginCheck(preg_match('/^[0-9A-Za-z]{8}$/D',$oldName)===1, 'already submitted alnum8 hostname remains unchanged');
pluginCheck(str_starts_with($nameMethod->invoke($driver,$identity),'jy-'), 'original pre-rule task identities remain unchanged');
$schema['products']['host']=json_encode($schema['products']['host']);$schema['products']['password']=json_encode($schema['products']['password']);
pluginCheck($contextMethod->invoke($driver,$schema)===$context, 'upstream string JSON and decoded generation rules have equal semantics');
pluginRejects(static fn() => $passwordPolicy::validate('ValidPassword12!', $context), 'upstream alphanumeric generation policy rejects extra symbols');
pluginCheck($driver instanceof \App\Plugins\Providers\ReinstallDriverInterface
    && !(\App\Plugins\Providers\ProviderRegistry::installed()->get('manual') instanceof \App\Plugins\Providers\ReinstallDriverInterface), 'reinstall is optional and cannot fall back to manual create');
$options=new \App\Plugins\Providers\ReinstallOptions([['id'=>'1173023','name'=>'Debian-12.0_x64']]);
pluginCheck($options->matches(['data_disk'=>'no_format_requested','password_mode'=>'upstream_generated','images'=>[['name'=>'Debian-12.0_x64','id'=>'1173023']]]), 'frozen reinstall options survive MySQL JSON object key normalization');
pluginCheck(!$options->matches(['data_disk'=>'no_format_requested','password_mode'=>'upstream_generated','images'=>[['id'=>1173023,'name'=>'Debian-12.0_x64']]]), 'reinstall option matching preserves strict id types');
pluginRejects(static fn()=>new \App\Plugins\Providers\ReinstallOptions([['id'=>'1','name'=>'Debian'],['id'=>'1','name'=>'Ubuntu']]), 'ambiguous duplicate image ids fail closed');
pluginRejects(static fn()=>new \App\Plugins\Providers\ReinstallOptions([['id'=>'1','name'=>'<script>image</script>']]), 'unsafe image labels fail closed');
pluginRejects(static fn()=>new \App\Plugins\Providers\ReinstallOptions([],dataDisk:'format_all'), 'unproven destructive data disk modes are rejected');
pluginCheck($driver instanceof \App\Plugins\Providers\ServiceStatusDriverInterface
    && !(\App\Plugins\Providers\ProviderRegistry::installed()->get('manual') instanceof \App\Plugins\Providers\ServiceStatusDriverInterface), 'service observation is an explicit optional driver capability');
pluginRejects(static fn()=>new \App\Plugins\Providers\ServiceStatus('powered_off'), 'power state cannot masquerade as service lifecycle');
pluginRejects(static fn()=>new \App\Plugins\Providers\ServiceStatus('suspended','overdue'), 'suspension category uses a finite trusted vocabulary');
pluginRejects(static fn()=>new \App\Plugins\Providers\ServiceStatus('active',dueDate:'2026-02-30 00:00:00'), 'invalid upstream expiry is not persisted');
pluginRejects(static fn()=>new \App\Plugins\Providers\ServiceStatus('unknown',code:'raw secret response'), 'raw upstream text cannot be a status code');
echo "{$checks} plugin checks passed.\n";
