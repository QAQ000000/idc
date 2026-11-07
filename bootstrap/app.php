<?php
declare(strict_types=1);

use App\Core\ApiException;
use App\Http\Middleware\{ApiContext, AuthenticateClient, AuthenticateAdmin, VerifyCaptcha};
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\{Exceptions, Middleware};
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

// 部分 PHP-FPM/cli-server 环境禁用 putenv 且 variables_order 不含 E。
// 将已有进程环境交给 Laravel 的 EnvConstAdapter，不更改系统环境或 .env。
$_ENV += getenv();

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', apiPrefix: 'api/v1', then: function (): void {
        Illuminate\Support\Facades\Route::get('/health', function () {
            App\Core\Database::getInstance()->query('SELECT 1');
            return App\Support\ApiResponse::success(['status' => 'ok']);
        });
    })
    ->withMiddleware(function (Middleware $middleware): void {
        // 不自动 trim/改写 JSON，保留密码、金额字符串及既有错误语义。
        $middleware->use([ApiContext::class, Illuminate\Http\Middleware\HandleCors::class]);
        $middleware->alias(['admin' => AuthenticateAdmin::class, 'client' => AuthenticateClient::class, 'captcha' => VerifyCaptcha::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn () => true);
        $exceptions->dontReport([ApiException::class]);
        $exceptions->report(function (Throwable $error): bool {
            // 不将请求、SQL、连接串、Token 或异常消息写入日志。
            Illuminate\Support\Facades\Log::error('Request/command failed', ['exception_type' => get_class($error)]);
            return false;
        });
        $exceptions->render(function (Throwable $error, Request $request) {
            if ($error instanceof ApiException) {
                return App\Support\ApiResponse::error($error->getMessage(), $error->getCode(), $error->httpStatus);
            }
            if ($error instanceof ValidationException) {
                return App\Support\ApiResponse::error('Invalid request parameters', 422, 422);
            }
            if ($error instanceof HttpExceptionInterface) {
                $status = $error->getStatusCode();
                return App\Support\ApiResponse::error($status === 404 ? 'Not Found' : 'Request rejected', $status, $status);
            }
            return App\Support\ApiResponse::error('Internal server error', 500, 500);
        });
    })->create();

if ($environmentPath = getenv('APP_ENVIRONMENT_PATH')) {
    $app->useEnvironmentPath($environmentPath);
}
return $app;
