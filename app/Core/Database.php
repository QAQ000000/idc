<?php

namespace App\Core;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;
    private static int $transactionDepth = 0;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $host = getenv('DB_HOST') ?: '127.0.0.1';
            $port = getenv('DB_PORT') ?: '3306';
            $dbname = getenv('DB_DATABASE') ?: 'jianyun';
            $user = getenv('DB_USERNAME');
            $pass = getenv('DB_PASSWORD');
            // 凭据只允许来自环境变量；缺少时必须拒绝启动，严禁代码内置兜底凭据。
            if ($user === false || $user === '' || $pass === false || $pass === '') {
                throw new \RuntimeException(
                    'Missing required environment variables DB_USERNAME / DB_PASSWORD. Credentials must be provided via the environment, never hardcoded.'
                );
            }

            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                // 全库 DATETIME 约定为 UTC 存取（见 docs/DATABASE.md），连接层强制 UTC 时区
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
            ];

            try {
                self::$instance = new PDO($dsn, $user, $pass, $options);
            } catch (PDOException $e) {
                throw new \RuntimeException("Database connection failed: " . $e->getMessage());
            }
        }

        return self::$instance;
    }

    /**
     * 在事务中执行回调 (支持嵌套事务与 SAVEPOINT 保存点重入)
     */
    public static function transaction(callable $callback)
    {
        $pdo = self::getInstance();
        $depth = self::$transactionDepth;
        if ($depth === 0) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT sp_' . self::$transactionDepth);
        }
        self::$transactionDepth++;

        try {
            $result = $callback($pdo);
            if ($depth === 0) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT sp_' . $depth);
            }
            return $result;
        } catch (\Throwable $e) {
            if ($depth === 0) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } else {
                $pdo->exec('ROLLBACK TO SAVEPOINT sp_' . $depth);
            }
            throw $e;
        } finally {
            self::$transactionDepth = $depth;
        }
    }
}
