<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
if (config('app.env') !== 'test' || config('database.connections.mysql.database') !== 'jianyun_test') {
    throw new RuntimeException('Isolated database required');
}
$db = App\Core\Database::getInstance();
if (($argv[1] ?? '') === 'seed') {
    $db->exec("INSERT INTO notification_deliveries(fingerprint,channel,admin_id,target,status,updated_at) VALUES('runtime:unknown-mail','mail',1,'fixture@example.test','sending',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 16 MINUTE))");
}
echo json_encode($db->query("SELECT id,status,error_message,sent_at FROM notification_deliveries WHERE fingerprint='runtime:unknown-mail'")->fetch(), JSON_THROW_ON_ERROR),PHP_EOL;
