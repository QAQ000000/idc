<?php

return [
    'default' => 'mysql',
    'connections' => ['mysql' => [
        'driver' => 'mysql', 'host' => env('DB_HOST', '127.0.0.1'), 'port' => env('DB_PORT', '3306'),
        'database' => env('DB_DATABASE', 'jianyun'), 'username' => env('DB_USERNAME'), 'password' => env('DB_PASSWORD'),
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
        'timezone' => '+00:00', 'isolation_level' => 'REPEATABLE READ',
        'options' => [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false],
    ]],
    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => false],
    'redis' => ['client' => 'phpredis', 'options' => ['prefix' => ''], 'default' => [
        'host' => env('REDIS_HOST', '127.0.0.1'), 'port' => env('REDIS_PORT', 6379),
        'password' => env('REDIS_PASSWORD'), 'database' => env('REDIS_DB', 0),
        'timeout' => 2, 'read_timeout' => 2,
    ]],
];
