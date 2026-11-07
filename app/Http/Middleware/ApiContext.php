<?php
declare(strict_types=1);
namespace App\Http\Middleware;

use Closure;
use App\Middlewares\{AuthMiddleware, AdminAuthMiddleware};
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ApiContext
{
    public function handle(Request $request, Closure $next): Response
    {
        AuthMiddleware::reset();
        AdminAuthMiddleware::reset();
        try {
            $response = $next($request);
            $response->headers->set('Cache-Control', 'no-store');
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            return $response;
        } finally {
            AuthMiddleware::reset();
            AdminAuthMiddleware::reset();
        }
    }
}
