<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Middlewares\AdminAuthMiddleware;
use App\Services\AdminAuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final class AdminAuthController
{
    public function login(AdminAuthService $service): JsonResponse
    {
        return ApiResponse::success($service->login(Request::body()))->header('Cache-Control', 'no-store');
    }

    public function me(): JsonResponse
    {
        return ApiResponse::success(AdminAuthMiddleware::admin())->header('Cache-Control', 'no-store');
    }
}
