<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Middlewares\AuthMiddleware;
use App\Services\{WalletService,FinanceQueryService};
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final class WalletController
{
    public function show(WalletService $s): JsonResponse
    {
        return ApiResponse::success($s->summary((int) AuthMiddleware::user()['id']))->header('Cache-Control', 'no-store');
    }

    public function recharge(WalletService $s): JsonResponse
    {
        return ApiResponse::success($s->recharge((int) AuthMiddleware::user()['id'], Request::body()));
    }

    public function transactions(FinanceQueryService $s): JsonResponse
    {
        return ApiResponse::success($s->wallet((int) AuthMiddleware::user()['id'], request()->query()))->header('Cache-Control', 'no-store');
    }
}
