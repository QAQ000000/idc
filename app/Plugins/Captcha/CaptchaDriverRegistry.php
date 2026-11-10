<?php

declare(strict_types=1);

namespace App\Plugins\Captcha;

/** 插件装配边界：业务服务只按标识解析接口，不依赖任何具体驱动。 */
final class CaptchaDriverRegistry
{
    /** @param array<string, callable(): CaptchaDriverInterface> $factories */
    public function __construct(private readonly array $factories) {}

    public static function installed(): self
    {
        return self::fromDirectory(dirname(__DIR__, 3).'/plugins/captcha');
    }

    public static function fromDirectory(string $directory): self
    {
        $root = realpath($directory);
        if ($root === false || ! is_dir($root)) {
            throw new \RuntimeException('Captcha plugin directory is unavailable');
        }
        $factories = [];
        foreach (glob($root.'/*/manifest.json') ?: [] as $file) {
            $id = basename(dirname($file));
            $pluginDirectory = $root.'/'.$id;
            if (realpath(dirname($file)) !== $pluginDirectory || realpath($file) !== $pluginDirectory.'/manifest.json') {
                throw new \RuntimeException('Captcha manifest is outside its plugin directory');
            }
            $manifest = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
            $entry = is_array($manifest) ? ($manifest['entry'] ?? null) : null;
            if (! is_array($manifest) || ! preg_match('/^[a-z][a-z0-9_]*$/D', $id) || ($manifest['id'] ?? null) !== $id
                || ($manifest['type'] ?? null) !== 'captcha' || ! is_string($entry)
                || ! preg_match('/^[A-Z][A-Za-z0-9]*\.php$/D', $entry)) {
                throw new \RuntimeException('Invalid captcha plugin manifest');
            }
            $class = 'Plugins\\captcha\\'.$id.'\\'.substr($entry, 0, -4);
            $entryFile = $pluginDirectory.'/'.$entry;
            // 加载 PHP 之前确认物理归属；符号链接不能把清单或入口引向目录外。
            if (! is_file($entryFile) || realpath($entryFile) !== $entryFile) {
                throw new \RuntimeException('Captcha entry is outside its plugin directory');
            }
            if (! class_exists($class, false)) {
                require_once $entryFile;
            }
            if (! class_exists($class, false) || ! is_subclass_of($class, CaptchaDriverInterface::class)) {
                throw new \RuntimeException('Captcha entry must implement CaptchaDriverInterface');
            }
            $reflection = new \ReflectionClass($class);
            if (realpath((string) $reflection->getFileName()) !== $entryFile || ! $reflection->isInstantiable()
                || ($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
                throw new \RuntimeException('Captcha entry must be local and instantiable without constructor arguments');
            }
            $factories[$id] = static fn (): CaptchaDriverInterface => new $class();
        }

        return new self($factories);
    }

    public function get(string $id): CaptchaDriverInterface
    {
        if (! isset($this->factories[$id])) {
            throw new \RuntimeException('Captcha driver is not installed');
        }
        $driver = ($this->factories[$id])();
        if (! $driver instanceof CaptchaDriverInterface || $driver->getDriverId() !== $id) {
            throw new \RuntimeException('Captcha driver identity does not match its registration');
        }

        return $driver;
    }
}
