<?php
declare(strict_types=1);
namespace App\Core;

final class Request
{
    private static ?array $body = null;

    public static function body(): array
    {
        if (self::$body !== null) {
            return self::$body;
        }
        if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
            throw new ApiException('Content-Type must be application/json', 415, 400);
        }
        $raw = file_get_contents('php://input', false, null, 0, 65537);
        if ($raw === false || strlen($raw) > 65536) {
            throw new ApiException('Request body too large', 413, 400);
        }
        try {
            $object = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
            if (!$object instanceof \stdClass) {
                throw new ApiException('JSON body must be an object');
            }
            return self::$body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiException('Invalid JSON body');
        }
    }

    public static function text(array $body, string $key, int $min = 1, int $max = 100): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value) || strlen($value) < $min || strlen($value) > $max) {
            throw new ApiException("Invalid {$key}");
        }
        return $value;
    }

    public static function id(mixed $value): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$value)) {
            throw new ApiException('Invalid resource ID');
        }
        return (int)$value;
    }
}
