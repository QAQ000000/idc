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
    } catch (RuntimeException | JsonException) {
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
echo "{$checks} plugin checks passed.\n";
