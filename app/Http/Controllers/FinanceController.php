<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Middlewares\AdminAuthMiddleware;
use App\Services\FinanceQueryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final class FinanceController
{
    public function index(string $kind, FinanceQueryService $s): JsonResponse
    {
        return ApiResponse::success($s->adminListing((int) AdminAuthMiddleware::admin()['id'], $kind, request()->query()))->header('Cache-Control', 'no-store');
    }

    public function show(string $id, FinanceQueryService $s): JsonResponse
    {
        return ApiResponse::success($s->detail((int) AdminAuthMiddleware::admin()['id'], Request::id($id), request()->query()))->header('Cache-Control', 'no-store');
    }
}
