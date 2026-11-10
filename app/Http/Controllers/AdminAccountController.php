<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Middlewares\AdminAuthMiddleware as Identity;
use App\Services\AdminAccountService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final class AdminAccountController
{
    private function actor(): int
    {
        return (int) Identity::admin()['id'];
    }

    private function response(array $data): JsonResponse
    {
        return ApiResponse::success($data)->header('Cache-Control', 'no-store');
    }

    public function index(AdminAccountService $s): JsonResponse
    {
        return $this->response($s->listing($this->actor(), Identity::authVersion(), request()->query()));
    }

    public function show(string $id, AdminAccountService $s): JsonResponse
    {
        return $this->response($s->detail($this->actor(), Identity::authVersion(), Request::id($id)));
    }

    public function store(AdminAccountService $s): JsonResponse
    {
        return $this->response($s->create($this->actor(), Identity::authVersion(), Request::body()));
    }

    public function update(string $id, AdminAccountService $s): JsonResponse
    {
        return $this->response($s->update($this->actor(), Identity::authVersion(), Request::id($id), Request::body()));
    }

    public function reset(string $id, AdminAccountService $s): JsonResponse
    {
        return $this->response($s->resetPassword($this->actor(), Identity::authVersion(), Request::id($id), Request::body()));
    }

    public function security(AdminAccountService $s): JsonResponse
    {
        return $this->response($s->security($this->actor(), Identity::authVersion()));
    }

    public function password(AdminAccountService $s): JsonResponse
    {
        return $this->response($s->changePassword($this->actor(), Identity::authVersion(), Request::body()));
    }
}
