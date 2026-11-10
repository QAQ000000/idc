<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Middlewares\AdminAuthMiddleware;
use App\Services\AdminProductService;
use App\Support\ApiResponse;
use Illuminate\Http\{JsonResponse,Request as HttpRequest};

final class AdminProductController
{
    public function index(HttpRequest $r, AdminProductService $s): JsonResponse
    {
        return ApiResponse::success($s->listing($r->query()));
    }

    public function providers(AdminProductService $s): JsonResponse
    {
        return ApiResponse::success($s->providers());
    }

    public function show(string $id, HttpRequest $r, AdminProductService $s): JsonResponse
    {
        return ApiResponse::success($s->detail(Request::id($id), $r->query()));
    }

    public function update(string $id, AdminProductService $s): JsonResponse
    {
        return ApiResponse::success($s->update((int) AdminAuthMiddleware::admin()['id'], Request::id($id), Request::body()));
    }

    public function preview(AdminProductService $s): JsonResponse
    {
        return ApiResponse::success($s->preview((int) AdminAuthMiddleware::admin()['id'], Request::body()));
    }

    public function import(AdminProductService $s): JsonResponse
    {
        return ApiResponse::success($s->snapshot((int) AdminAuthMiddleware::admin()['id'], Request::body()));
    }

    public function previewRefresh(string $id, AdminProductService $s): JsonResponse
    {
        return ApiResponse::success($s->preview((int) AdminAuthMiddleware::admin()['id'], Request::body(), Request::id($id)));
    }

    public function refresh(string $id, AdminProductService $s): JsonResponse
    {
        return ApiResponse::success($s->snapshot((int) AdminAuthMiddleware::admin()['id'], Request::body(), Request::id($id)));
    }
}
