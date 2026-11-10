<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
set_exception_handler(static function (Throwable $e): never {
    fwrite(STDERR, 'Notification subprocess failed: '.$e::class."\n");
    exit(1);
});
if (config('app.env') !== 'test' || config('database.connections.mysql.database') !== 'jianyun_test') {
    throw new RuntimeException('Isolated database required');
}
echo json_encode((new App\Services\NotificationService())->dispatch(), JSON_THROW_ON_ERROR),PHP_EOL;
