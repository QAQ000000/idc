<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Core\Request;
use App\Middlewares\AdminAuthMiddleware;
use App\Services\TaskResolutionService;
use App\Support\ApiResponse;
final class TaskController
{
    public function resolve(string $id, TaskResolutionService $s): \Illuminate\Http\JsonResponse
    {
        (new AdminAuthMiddleware())->handle();
        $s->resolve((int)AdminAuthMiddleware::admin()['id'],Request::id($id),Request::body());
        return ApiResponse::success(['resolved'=>true]);
    }
}
