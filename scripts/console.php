<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

use App\Core\{Database, Request};
use App\Services\{BillingService, DemoService};

try {
    switch ($argv[1] ?? '') {
        case 'db:init':
            $db = Database::getInstance();
            if ($db->query('SHOW TABLES')->fetchColumn() !== false) {
                throw new RuntimeException('db:init requires an empty database; use reviewed migrations for existing data');
            }
            // mysql 客户端 DELIMITER 指令不属于 SQL，按脚本声明切分。
            $delimiter = ';';
            $buffer = '';
            foreach (file(dirname(__DIR__) . '/database/schema.sql') as $line) {
                if (preg_match('/^DELIMITER\s+(\S+)/', trim($line), $match)) {
                    $delimiter = $match[1];
                    continue;
                }
                $buffer .= $line;
                if (str_ends_with(rtrim($buffer), $delimiter)) {
                    $sql = substr(rtrim($buffer), 0, -strlen($delimiter));
                    $db->exec($sql);
                    $buffer = '';
                }
            }
            echo "Schema initialized (26 tables).\n";
            break;
        case 'demo:seed':
            echo json_encode(['product_id' => (new DemoService())->seed()], JSON_THROW_ON_ERROR) . "\n";
            break;
        case 'demo:recharge':
            echo json_encode(['invoice_id' => (new DemoService())->recharge(Request::id($argv[2] ?? null), $argv[3] ?? '', $argv[4] ?? '')], JSON_THROW_ON_ERROR) . "\n";
            break;
        case 'invoices:expire':
            $rows = Database::getInstance()->query("SELECT id, user_id FROM invoices WHERE type = 'new' AND status = 'unpaid' AND quote_expired_at <= UTC_TIMESTAMP() ORDER BY id LIMIT 500")->fetchAll();
            foreach ($rows as $row) {
                (new BillingService())->cancel((int)$row['user_id'], (int)$row['id'], true);
            }
            echo 'Processed ' . count($rows) . " expired invoices.\n";
            break;
        default:
            fwrite(STDERR, "Commands: db:init | demo:seed | demo:recharge USER_ID AMOUNT UNIQUE_REFERENCE | invoices:expire\n");
            exit(1);
    }
} catch (Throwable $e) {
    // 凭据/SQL 不进入 CLI 报错；业务错误可读。
    fwrite(STDERR, ($e instanceof \App\Core\ApiException ? $e->getMessage() : 'Command failed (' . get_class($e) . ')') . "\n");
    exit(1);
}
