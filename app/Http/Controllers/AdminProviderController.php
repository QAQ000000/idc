<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Middlewares\AdminAuthMiddleware;
use App\Services\AdminProviderService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final class AdminProviderController
{
    private function admin(): int
    {
        return (int) AdminAuthMiddleware::admin()['id'];
    }

    private function response(array $data): JsonResponse
    {
        return ApiResponse::success($data)->header('Cache-Control', 'no-store');
    }

    public function index(AdminProviderService $s): JsonResponse
    {
        return $this->response($s->listing($this->admin(), request()->query()));
    }

    public function show(string $id, AdminProviderService $s): JsonResponse
    {
        return $this->response($s->detail($this->admin(), Request::id($id), request()->query()));
    }

    public function drivers(AdminProviderService $s): JsonResponse
    {
        return $this->response($s->drivers($this->admin()));
    }

    public function store(AdminProviderService $s): JsonResponse
    {
        return $this->response($s->create($this->admin(), Request::body()));
    }

    public function update(string $id, AdminProviderService $s): JsonResponse
    {
        return $this->response($s->update($this->admin(), Request::id($id), Request::body()));
    }

    public function verify(string $id, AdminProviderService $s): JsonResponse
    {
        return $this->response($s->verify($this->admin(), Request::id($id), Request::body()));
    }

    public function catalog(string $id, \App\Services\UpstreamCatalogService $s): JsonResponse
    {
        $refresh = request()->query('refresh', '0');
        if (! in_array($refresh, ['0', '1'], true)) {
            throw new \App\Core\ApiException('Invalid refresh flag');
        }

        return $this->response($s->listing($this->admin(), AdminAuthMiddleware::authVersion(), Request::id($id), $refresh === '1'));
    }
}
