<?php
declare(strict_types=1);
namespace App\Http\Middleware;

use App\Middlewares\AdminAuthMiddleware;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        (new AdminAuthMiddleware())->handle();
        return $next($request);
    }
}
