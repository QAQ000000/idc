<?php
declare(strict_types=1);
namespace App\Support;

use Illuminate\Http\JsonResponse;

final class ApiResponse
{
    public static function success(mixed $data = null): JsonResponse
    {
        return response()->json(['code' => 0, 'msg' => 'ok', 'data' => $data]);
    }
    public static function error(string $message, int $code, int $status): JsonResponse
    {
        return response()->json(['code' => $code, 'msg' => $message, 'data' => null], $status);
    }
}
