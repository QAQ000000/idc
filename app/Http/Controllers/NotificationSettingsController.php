<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Core\Request;
use App\Middlewares\AdminAuthMiddleware;
use App\Services\NotificationSettingsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
final class NotificationSettingsController
{
    private function admin(): int { return (int)AdminAuthMiddleware::admin()['id']; }
    public function show(NotificationSettingsService $service): JsonResponse
    {
        return ApiResponse::success($service->show($this->admin()))->header('Cache-Control','no-store');
    }
    public function save(NotificationSettingsService $service): JsonResponse
    {
        $service->save($this->admin(),Request::body());
        return ApiResponse::success(['saved'=>true])->header('Cache-Control','no-store');
    }
    public function tickets(NotificationSettingsService $service): JsonResponse
    {
        $service->saveTickets($this->admin(),Request::body());
        return ApiResponse::success(['saved'=>true])->header('Cache-Control','no-store');
    }
}
