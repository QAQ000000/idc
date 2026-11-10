<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\{Request, ApiException};
use App\Middlewares\AdminAuthMiddleware;
use App\Services\{TaskResolutionService, TaskInspectionService};
use App\Support\ApiResponse;

final class TaskController
{
    public function index(\Illuminate\Http\Request $request, TaskInspectionService $service): \Illuminate\Http\JsonResponse
    {
        return ApiResponse::success($service->listing($request->query(), (int) AdminAuthMiddleware::admin()['id']))->header('Cache-Control', 'no-store');
    }

    public function show(string $id, \Illuminate\Http\Request $request, TaskInspectionService $service): \Illuminate\Http\JsonResponse
    {
        return ApiResponse::success($service->detail(Request::id($id), AdminAuthMiddleware::admin(), $request->query()))->header('Cache-Control', 'no-store');
    }

    public function resolve(string $id, TaskResolutionService $s): \Illuminate\Http\JsonResponse
    {
        if (AdminAuthMiddleware::admin()['role'] !== 'super_admin') {
            throw new ApiException('Active super_admin required', 403, 403);
        }
        $s->resolve((int) AdminAuthMiddleware::admin()['id'], Request::id($id), Request::body());

        return ApiResponse::success(['resolved' => true]);
    }
}
