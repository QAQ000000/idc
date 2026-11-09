<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Core\Request;
use App\Middlewares\AdminAuthMiddleware;
use App\Services\ManualAdjustmentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
final class ManualAdjustmentController
{
    private function admin(): int { return (int)AdminAuthMiddleware::admin()['id']; }
    public function show(string $id,ManualAdjustmentService $s): JsonResponse { return ApiResponse::success($s->detail($this->admin(),Request::id($id)))->header('Cache-Control','no-store'); }
    public function preview(string $id,ManualAdjustmentService $s): JsonResponse { return ApiResponse::success($s->preview($this->admin(),Request::id($id),Request::body()))->header('Cache-Control','no-store'); }
    public function settle(string $id,ManualAdjustmentService $s): JsonResponse { return ApiResponse::success($s->settle($this->admin(),Request::id($id),Request::body()))->header('Cache-Control','no-store'); }
}
