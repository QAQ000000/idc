<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Middlewares\AdminAuthMiddleware;
use App\Services\SiteSettingsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final class SiteSettingsController
{
    public function publicConfig(SiteSettingsService $service): JsonResponse
    {
        return ApiResponse::success($service->publicConfig())->header('Cache-Control', 'no-store');
    }

    public function show(SiteSettingsService $service): JsonResponse
    {
        return ApiResponse::success($service->show((int) AdminAuthMiddleware::admin()['id'], AdminAuthMiddleware::authVersion()))->header('Cache-Control', 'no-store');
    }

    public function save(SiteSettingsService $service): JsonResponse
    {
        $service->save((int) AdminAuthMiddleware::admin()['id'], AdminAuthMiddleware::authVersion(), Request::body());

        return ApiResponse::success(['saved' => true])->header('Cache-Control', 'no-store');
    }
}
