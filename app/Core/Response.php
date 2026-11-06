<?php

namespace App\Core;

class Response
{
    public static function json(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function success(mixed $data = null, string $msg = 'ok'): void
    {
        self::json([
            'code' => 0,
            'msg' => $msg,
            'data' => $data
        ]);
    }

    public static function error(string $msg = 'error', int $code = 400, int $statusCode = 400): void
    {
        self::json([
            'code' => $code,
            'msg' => $msg,
            'data' => null
        ], $statusCode);
    }
}
