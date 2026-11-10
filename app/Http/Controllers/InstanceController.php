<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Middlewares\AuthMiddleware;
use App\Services\InstanceService;
use App\Services\RenewalService;
use App\Support\ApiResponse;

final class InstanceController
{
    public function index(InstanceService $s): \Illuminate\Http\JsonResponse
    {
        return ApiResponse::success($s->list((int) AuthMiddleware::user()['id'], Request::id(request()->query('page', '1'))));
    }

    public function show(string $id, InstanceService $s): \Illuminate\Http\JsonResponse
    {
        return ApiResponse::success($s->detail((int) AuthMiddleware::user()['id'], Request::id($id)));
    }

    public function credentials(string $id, InstanceService $s): \Illuminate\Http\JsonResponse
    {
        return ApiResponse::success($s->credentials((int) AuthMiddleware::user()['id'], Request::id($id)))->header('Cache-Control', 'no-store');
    }

    public function consoleCheck(string $id, \App\Services\InstanceConsoleService $s): \Illuminate\Http\JsonResponse
    {
        return ApiResponse::success($s->check(request()->bearerToken() ?? '', Request::id($id)))->header('Cache-Control', 'no-store');
    }

    public function console(string $id, \App\Services\InstanceConsoleService $s): \Illuminate\Http\JsonResponse
    {
        return ApiResponse::success($s->issue(request()->bearerToken() ?? '', Request::id($id)))->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function runtime(string $id, \App\Services\InstancePowerService $s): \Illuminate\Http\JsonResponse
    {
        $view = request()->query('view', 'full');
        if (! in_array($view, ['full', 'tasks', 'observation', 'state'], true)) {
            throw new \App\Core\ApiException('Invalid runtime view');
        }
        if (in_array($view, ['observation', 'state'], true)) {
            return ApiResponse::success((new \App\Services\InstanceDisplayService())->power((int) AuthMiddleware::user()['id'], Request::id($id), $view === 'state'))->header('Cache-Control', 'no-store');
        }

        return ApiResponse::success($s->runtime((int) AuthMiddleware::user()['id'], Request::id($id), $view === 'tasks'))->header('Cache-Control', 'no-store');
    }

    public function serviceStatus(string $id, \App\Services\InstanceServiceStatusService $s): \Illuminate\Http\JsonResponse
    {
        return ApiResponse::success($s->reconcile(Request::id($id), (int) AuthMiddleware::user()['id']))->header('Cache-Control', 'no-store');
    }

    public function adminServiceStatus(string $id, \App\Services\InstanceServiceStatusService $s): \Illuminate\Http\JsonResponse
    {
        $admin = (int) \App\Middlewares\AdminAuthMiddleware::admin()['id'];

        return ApiResponse::success($s->reconcile(Request::id($id), null, $admin))->header('Cache-Control', 'no-store');
    }

    public function power(string $id, \App\Services\InstancePowerService $s): \Illuminate\Http\JsonResponse
    {
        return ApiResponse::success($s->submit((int) AuthMiddleware::user()['id'], Request::id($id), Request::body()));
    }

    public function reinstallOptions(string $id, \App\Services\InstanceReinstallService $s): \Illuminate\Http\JsonResponse
    {
        $view = request()->query('view', 'full');
        if (! in_array($view, ['full', 'tasks', 'images'], true)) {
            throw new \App\Core\ApiException('Invalid reinstall view');
        }
        if ($view === 'images') {
            return ApiResponse::success((new \App\Services\InstanceDisplayService())->images((int) AuthMiddleware::user()['id'], Request::id($id)))->header('Cache-Control', 'no-store');
        }

        return ApiResponse::success($s->view((int) AuthMiddleware::user()['id'], Request::id($id), $view === 'tasks'))->header('Cache-Control', 'no-store');
    }

    public function reinstall(string $id, \App\Services\InstanceReinstallService $s): \Illuminate\Http\JsonResponse
    {
        return ApiResponse::success($s->submit((int) AuthMiddleware::user()['id'], Request::id($id), Request::body()))->header('Cache-Control', 'no-store');
    }

    public function renew(string $id, RenewalService $s): \Illuminate\Http\JsonResponse
    {
        $body = Request::body();
        // First renewal closes one original billing cycle; arbitrary durations are not enabled.
        if (isset($body['months']) && $body['months'] !== 1) {
            throw new \App\Core\ApiException('Only one original billing cycle is enabled');
        }
        if (isset($body['use_balance']) && ! is_bool($body['use_balance'])) {
            throw new \App\Core\ApiException('Invalid use_balance');
        }
        $base = Request::text($body, 'base_due_date', 19, 19);
        $user = (int) AuthMiddleware::user()['id'];
        $invoice = $s->issue($user, Request::id($id), $base);
        if ($body['use_balance'] ?? false) {
            $invoice = (new \App\Services\BillingService())->pay($user, (int) $invoice['invoice_id']);
        }

        return ApiResponse::success($invoice);
    }

    public function automatic(string $id, RenewalService $s): \Illuminate\Http\JsonResponse
    {
        $enabled = Request::body()['enabled'] ?? null;
        if (! is_bool($enabled)) {
            throw new \App\Core\ApiException('enabled must be boolean');
        }
        $s->setAutomatic((int) AuthMiddleware::user()['id'], Request::id($id), $enabled);

        return ApiResponse::success(['auto_renew' => $enabled]);
    }
}
