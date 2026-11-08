<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Core\Request;
use App\Middlewares\AdminAuthMiddleware;
use App\Services\AdminQueryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
final class AdminQueryController
{
    public function customers(AdminQueryService $s): JsonResponse { return ApiResponse::success($s->customers((int)AdminAuthMiddleware::admin()['id'],request()->query()))->header('Cache-Control','no-store'); }
    public function customer(string $id,AdminQueryService $s): JsonResponse { return ApiResponse::success($s->customer((int)AdminAuthMiddleware::admin()['id'],Request::id($id),request()->query()))->header('Cache-Control','no-store'); }
    public function instances(AdminQueryService $s): JsonResponse { return ApiResponse::success($s->instances((int)AdminAuthMiddleware::admin()['id'],request()->query()))->header('Cache-Control','no-store'); }
    public function instance(string $id,AdminQueryService $s): JsonResponse { return ApiResponse::success($s->instance((int)AdminAuthMiddleware::admin()['id'],Request::id($id),request()->query()))->header('Cache-Control','no-store'); }
}
