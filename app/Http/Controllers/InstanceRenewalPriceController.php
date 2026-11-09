<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Core\Request;
use App\Middlewares\AdminAuthMiddleware;
use App\Services\InstanceRenewalPriceService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
final class InstanceRenewalPriceController
{
    public function show(string $id,InstanceRenewalPriceService $s): JsonResponse { return ApiResponse::success($s->detail((int)AdminAuthMiddleware::admin()['id'],Request::id($id)))->header('Cache-Control','no-store'); }
    public function update(string $id,InstanceRenewalPriceService $s): JsonResponse { return ApiResponse::success($s->update((int)AdminAuthMiddleware::admin()['id'],Request::id($id),Request::body()))->header('Cache-Control','no-store'); }
}
