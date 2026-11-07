<?php
declare(strict_types=1);
namespace App\Support;

use App\Core\Database;

/** 执行权威 SQL 文件；仅处理 MySQL 客户端 DELIMITER，不实现迁移调度。 */
final class SchemaSql
{
    public static function import(string $file): void
    {
        $db = Database::getInstance();
        $delimiter = ';';
        $buffer = '';
        foreach (file($file) as $line) {
            if (preg_match('/^DELIMITER\s+(\S+)/', trim($line), $match)) {
                $delimiter = $match[1];
                continue;
            }
            $buffer .= $line;
            if (str_ends_with(rtrim($buffer), $delimiter)) {
                $db->exec(substr(rtrim($buffer), 0, -strlen($delimiter)));
                $buffer = '';
            }
        }
    }
}
