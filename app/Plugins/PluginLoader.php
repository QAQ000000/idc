<?php

declare(strict_types=1);

namespace App\Plugins;

/** Only reviewed, physically local manifests can load executable plugin entries. */
final class PluginLoader
{
    public static function load(string $directory, string $type, string $contract): array
    {
        $root = realpath($directory);
        if (! $root || ! is_dir($root)) {
            return [];
        }
        if ($root !== rtrim($directory, '/')) {
            throw new \RuntimeException('Plugin root must be a physical directory');
        }
        $drivers = [];
        foreach (glob($root.'/*/manifest.json') ?: [] as $file) {
            $id = basename(dirname($file));
            $dir = $root.'/'.$id;
            if (realpath(dirname($file)) !== $dir || realpath($file) !== $dir.'/manifest.json') {
                throw new \RuntimeException('Plugin manifest escaped physical boundary');
            }
            $m = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
            $entry = $m['entry'] ?? '';
            if (! preg_match('/^[a-z][a-z0-9_]*$/D', $id) || ($m['id'] ?? '') !== $id
                || ($m['type'] ?? '') !== $type || ! is_string($entry) || ! preg_match('/^[A-Z][A-Za-z0-9]*\.php$/D', $entry)) {
                throw new \RuntimeException('Invalid plugin manifest');
            }
            $path = $dir.'/'.$entry;
            if (! is_file($path) || realpath($path) !== $path) {
                throw new \RuntimeException('Plugin entry escaped physical boundary');
            }
            $class = 'Plugins\\'.basename($root).'\\'.$id.'\\'.substr($entry, 0, -4);
            if (! class_exists($class, false)) {
                require_once $path;
            }
            if (! class_exists($class, false) || ! is_subclass_of($class, $contract)) {
                throw new \RuntimeException('Invalid plugin contract');
            }
            $r = new \ReflectionClass($class);
            if (realpath((string) $r->getFileName()) !== $path || ! $r->isInstantiable() || ($r->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
                throw new \RuntimeException('Invalid plugin implementation origin');
            }
            $drivers[$id] = static fn () => new $class();
        }

        return $drivers;
    }
}
