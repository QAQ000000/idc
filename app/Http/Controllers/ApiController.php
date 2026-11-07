<?php
declare(strict_types=1);
namespace App\Http\Controllers;

use App\Core\{ApiException, Database, Request, Security};
use App\Middlewares\AuthMiddleware;
use App\Services\{AuthService, BillingService, CaptchaService, CatalogService};
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final class ApiController
{
    public function captcha(): JsonResponse
    {
        $scene = request()->query('scene', '');
        if (!is_string($scene)) { throw new ApiException('Invalid captcha scene'); }
        return ApiResponse::success(app(CaptchaService::class)->config($scene, Security::getClientIp()));
    }
    public function register(AuthService $auth): JsonResponse { return ApiResponse::success($auth->register(Request::body())); }
    public function login(AuthService $auth): JsonResponse { return ApiResponse::success($auth->login(Request::body())); }
    public function me(): JsonResponse
    {
        return ApiResponse::success(AuthMiddleware::user() + ['role' => 'client']);
    }
    public function products(CatalogService $catalog): JsonResponse { return ApiResponse::success($catalog->products()); }
    public function calculate(string $id, CatalogService $catalog): JsonResponse
    {
        $body = Request::body();
        $options = $body['config_options'] ?? [];
        if (!is_array($options)) { throw new ApiException('Invalid config_options'); }
        $catalog->preflight(Request::id($id), Request::text($body, 'billing_cycle', 1, 20), $options);
        $quote = $catalog->quote(Database::getInstance(), Request::id($id), Request::text($body, 'billing_cycle', 1, 20), $options);
        unset($quote['product']);
        return ApiResponse::success($quote);
    }
    public function checkout(BillingService $billing): JsonResponse { return ApiResponse::success($billing->checkout((int)AuthMiddleware::user()['id'], Request::body())); }
    public function invoice(string $id, BillingService $billing): JsonResponse { return ApiResponse::success($billing->invoice((int)AuthMiddleware::user()['id'], Request::id($id))); }
    public function pay(string $id, BillingService $billing): JsonResponse
    {
        $gateway = Request::text(Request::body(), 'gateway', 1, 50);
        if ($gateway !== 'balance') { return ApiResponse::success(app(\App\Services\PaymentService::class)->checkout((int)AuthMiddleware::user()['id'], Request::id($id), $gateway)); }
        return ApiResponse::success($billing->pay((int)AuthMiddleware::user()['id'], Request::id($id)));
    }
    public function cancel(string $id, BillingService $billing): JsonResponse { return ApiResponse::success($billing->cancel((int)AuthMiddleware::user()['id'], Request::id($id))); }
}
