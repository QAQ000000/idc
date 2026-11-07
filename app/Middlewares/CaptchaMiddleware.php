<?php
declare(strict_types=1);
namespace App\Middlewares;

use App\Core\{ApiException, Request};
use App\Services\CaptchaService;

final class CaptchaMiddleware
{
    public function __construct(private readonly string $scene) {}

    public function handle(): bool
    {
        $payload = Request::body()['captcha_payload'] ?? [];
        if (!is_array($payload)) {
            throw new ApiException('Invalid captcha payload', 422, 422);
        }
        (new CaptchaService())->verify($this->scene, $payload, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        return true;
    }
}
