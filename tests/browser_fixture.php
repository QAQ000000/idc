<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

if (config('app.env') !== 'test' || config('database.connections.mysql.database') !== 'jianyun_test') {
    throw new RuntimeException('Browser fixture requires the isolated test database');
}
$db = App\Core\Database::getInstance();
$db->prepare('INSERT INTO system_settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')
    ->execute(['captcha_scenes', json_encode(['login' => ['enabled' => false], 'register' => ['enabled' => false]])]);
$session = (new App\Services\AuthService())->register(['email' => 'browser@example.test', 'password' => 'BrowserFixture!2026']);
$demo = new App\Services\DemoService();
$demo->recharge((int)$session['user']['id'], '100.00', 'browser-fixture');
$product = $demo->seed();
echo json_encode(['product_id' => $product], JSON_THROW_ON_ERROR), PHP_EOL;
