<?php
declare(strict_types=1);

date_default_timezone_set('UTC');
spl_autoload_register(static function (string $class): void {
    $prefixes = ['App\\' => __DIR__ . '/app/', 'Plugins\\' => __DIR__ . '/plugins/'];
    foreach ($prefixes as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $file = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});
