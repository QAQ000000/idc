<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Core\Request;
use App\Middlewares\AuthMiddleware;
use App\Services\InstanceService;
use App\Support\ApiResponse;
final class InstanceController
{
    public function index(InstanceService $s): \Illuminate\Http\JsonResponse { return ApiResponse::success($s->list((int)AuthMiddleware::user()['id'],Request::id(request()->query('page','1')))); }
    public function show(string $id,InstanceService $s): \Illuminate\Http\JsonResponse { return ApiResponse::success($s->detail((int)AuthMiddleware::user()['id'],Request::id($id))); }
    public function credentials(string $id,InstanceService $s): \Illuminate\Http\JsonResponse { return ApiResponse::success($s->credentials((int)AuthMiddleware::user()['id'],Request::id($id))); }
}
