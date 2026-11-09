<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Core\Request;
use App\Middlewares\AdminAuthMiddleware;
use App\Services\PaymentSettingsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
final class PaymentSettingsController
{
    public function show(PaymentSettingsService $service): JsonResponse
    {
        return ApiResponse::success($service->show((int)AdminAuthMiddleware::admin()['id']))->header('Cache-Control','no-store');
    }
    public function save(PaymentSettingsService $service): JsonResponse
    {
        $service->save((int)AdminAuthMiddleware::admin()['id'],Request::body());
        return ApiResponse::success(['saved'=>true])->header('Cache-Control','no-store');
    }
}
