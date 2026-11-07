<?php
declare(strict_types=1);
namespace App\Http\Middleware;

use Closure;
use App\Middlewares\AuthMiddleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateClient
{
    public function handle(Request $request, Closure $next): Response
    {
        (new AuthMiddleware())->handle();
        return $next($request);
    }
}
