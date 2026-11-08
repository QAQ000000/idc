<?php
declare(strict_types=1);
namespace App\Core;

use Illuminate\Support\Facades\DB;
use PDO;

/** 领域兼容入口；连接与嵌套事务仅由 Laravel 管理。 */
final class Database
{
    public static function getInstance(): PDO
    {
        $credentials = config('database.connections.mysql');
        if (!is_string($credentials['username']) || $credentials['username'] === ''
            || !is_string($credentials['password']) || $credentials['password'] === '') {
            throw new \RuntimeException('DB_USERNAME and DB_PASSWORD are required');
        }
        return DB::connection()->getPdo();
    }

    public static function transaction(callable $callback, int $attempts = 1): mixed
    {
        return DB::connection()->transaction(static fn() => $callback(self::getInstance()), $attempts);
    }
}
