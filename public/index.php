<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

use App\Core\{ApiException, Database, Request, Response, Router};
use App\Middlewares\{AuthMiddleware, CaptchaMiddleware};
use App\Services\{AuthService, BillingService, CaptchaService, CatalogService};

set_exception_handler(static function (Throwable $e): void {
    if ($e instanceof ApiException) {
        Response::error($e->getMessage(), $e->getCode(), $e->httpStatus);
    }
    // 不记录 SQL、请求体或密钥等异常上下文。
    error_log('JianYun request failed: ' . get_class($e));
    Response::error('Internal server error', 500, 500);
});
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$router = new Router();
$router->get('/health', static function (): void {
    Database::getInstance()->query('SELECT 1');
    Response::success(['status' => 'ok']);
});
$router->get('/api/v1/captcha/config', static function (): void {
    $scene = $_GET['scene'] ?? '';
    if (!is_string($scene)) {
        throw new ApiException('Invalid captcha scene');
    }
    Response::success((new CaptchaService())->config($scene, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
});
$router->post('/api/v1/auth/register', static fn() => Response::success((new AuthService())->register(Request::body())), [new CaptchaMiddleware('register')]);
$router->post('/api/v1/auth/login', static fn() => Response::success((new AuthService())->login(Request::body())), [new CaptchaMiddleware('login')]);
$router->get('/api/v1/auth/me', static fn() => Response::success(AuthMiddleware::user()), [AuthMiddleware::class]);
$router->get('/api/v1/products', static fn() => Response::success((new CatalogService())->products()));
$router->post('/api/v1/products/{id}/calculate', static function (array $params): void {
    $body = Request::body();
    $options = $body['config_options'] ?? [];
    if (!is_array($options)) {
        throw new ApiException('Invalid config_options');
    }
    $quote = (new CatalogService())->quote(Database::getInstance(), Request::id($params['id']), Request::text($body, 'billing_cycle', 1, 20), $options);
    unset($quote['product']);
    Response::success($quote);
});
$router->post('/api/v1/orders/checkout', static fn() => Response::success((new BillingService())->checkout((int)AuthMiddleware::user()['id'], Request::body())), [AuthMiddleware::class]);
$router->get('/api/v1/invoices/{id}', static fn(array $params) => Response::success((new BillingService())->invoice((int)AuthMiddleware::user()['id'], Request::id($params['id']))), [AuthMiddleware::class]);
$router->post('/api/v1/invoices/{id}/pay', static function (array $params): void {
    if (Request::text(Request::body(), 'gateway', 1, 50) !== 'balance') {
        throw new ApiException('Only balance payment is enabled');
    }
    Response::success((new BillingService())->pay((int)AuthMiddleware::user()['id'], Request::id($params['id'])));
}, [AuthMiddleware::class]);
$router->post('/api/v1/invoices/{id}/cancel', static fn(array $params) => Response::success((new BillingService())->cancel((int)AuthMiddleware::user()['id'], Request::id($params['id']))), [AuthMiddleware::class]);
$router->dispatch();
