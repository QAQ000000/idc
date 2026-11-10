<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Middlewares\AdminAuthMiddleware;
use App\Services\CaptchaSettingsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final class CaptchaSettingsController
{
    public function show(CaptchaSettingsService $service): JsonResponse
    {
        return ApiResponse::success($service->show((int) AdminAuthMiddleware::admin()['id'], AdminAuthMiddleware::authVersion()))->header('Cache-Control', 'no-store');
    }

    public function save(string $scene, CaptchaSettingsService $service): JsonResponse
    {
        $service->save((int) AdminAuthMiddleware::admin()['id'], AdminAuthMiddleware::authVersion(), $scene, Request::body());

        return ApiResponse::success(['saved' => true])->header('Cache-Control', 'no-store');
    }
}
