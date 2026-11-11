<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Middlewares\AdminAuthMiddleware;
use App\Services\CategoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final class CategoryController
{
    public function publicTree(CategoryService $s): JsonResponse
    {
        return ApiResponse::success($s->publicTree());
    }

    public function index(CategoryService $s): JsonResponse
    {
        return ApiResponse::success($s->adminList((int) AdminAuthMiddleware::admin()['id']));
    }

    public function show(string $id, CategoryService $s): JsonResponse
    {
        return ApiResponse::success($s->detail(Request::id($id), (int) AdminAuthMiddleware::admin()['id']));
    }

    public function store(CategoryService $s): JsonResponse
    {
        return ApiResponse::success($s->create((int) AdminAuthMiddleware::admin()['id'], Request::body()));
    }

    public function update(string $id, CategoryService $s): JsonResponse
    {
        return ApiResponse::success($s->update((int) AdminAuthMiddleware::admin()['id'], Request::id($id), Request::body()));
    }

    public function destroy(string $id, CategoryService $s): JsonResponse
    {
        return ApiResponse::success($s->delete((int) AdminAuthMiddleware::admin()['id'], Request::id($id), Request::body()));
    }
}
