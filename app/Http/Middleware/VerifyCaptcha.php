<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use App\Core\{ApiException, Request as Input, Security};
use App\Services\CaptchaService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyCaptcha
{
    public function handle(Request $request, Closure $next, string $scene): Response
    {
        $payload = Input::body()['captcha_payload'] ?? [];
        if (! is_array($payload)) {
            throw new ApiException('Invalid captcha payload', 422, 422);
        }
        app(CaptchaService::class)->verify($scene, $payload, Security::getClientIp());

        return $next($request);
    }
}
