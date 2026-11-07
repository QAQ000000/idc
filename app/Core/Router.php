<?php

namespace App\Core;

class Router
{
    private array $routes = [];
    private array $middlewares = [];

    public function add(string $method, string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $this->convertPathToRegex($path),
            'handler' => $handler,
            'middlewares' => $middlewares
        ];
    }

    public function get(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->add('GET', $path, $handler, $middlewares);
    }

    public function post(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->add('POST', $path, $handler, $middlewares);
    }

    public function put(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->add('PUT', $path, $handler, $middlewares);
    }

    public function delete(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->add('DELETE', $path, $handler, $middlewares);
    }

    public function dispatch(): void
    {
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        $this->handleCors($requestMethod);

        foreach ($this->routes as $route) {
            if ($route['method'] === $requestMethod && preg_match($route['pattern'], $requestUri, $matches)) {
                $params = array_filter($matches, '\is_string', ARRAY_FILTER_USE_KEY);

                // 执行中间件
                foreach ($route['middlewares'] as $mw) {
                    $mwInstance = is_string($mw) ? new $mw() : $mw;
                    $mwResult = $mwInstance->handle();
                    if ($mwResult === false) {
                        return; // 中间件拦截
                    }
                }

                // 执行 Handler
                $handler = $route['handler'];
                if (is_array($handler)) {
                    [$class, $action] = $handler;
                    $controller = new $class();
                    $controller->$action($params);
                } else {
                    $handler($params);
                }
                return;
            }
        }

        Response::error('Not Found', 404, 404);
    }

    /**
     * CORS 白名单校验：严禁 Access-Control-Allow-Origin 通配符 * 放行跨域读取。
     * - 允许来源由环境变量 CORS_ALLOWED_ORIGINS 配置（逗号分隔，如
     *   https://www.example.com,https://console.example.com）；未配置时仅放行同源请求。
     * - 命中白名单：回显该 Origin 并发送 CORS 头（Vary: Origin 保证缓存正确性）。
     * - 未命中：不发送任何 CORS 头，由浏览器侧拦截跨域读取。
     */
    private function handleCors(string $requestMethod): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        $raw = getenv('CORS_ALLOWED_ORIGINS') ?: '';
        $allowed = array_values(array_filter(array_map('trim', explode(',', $raw))));
        if ($origin !== '' && in_array($origin, $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin', false);
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        }
        if ($requestMethod === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    private function convertPathToRegex(string $path): string
    {
        $quoted = preg_quote($path, '#');
        return '#^' . preg_replace('/\\\\\{([a-zA-Z0-9_]+)\\\\\}/', '(?P<$1>[^/]+)', $quoted) . '$#D';
    }
}
