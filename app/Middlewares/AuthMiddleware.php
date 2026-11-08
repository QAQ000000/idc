<?php

namespace App\Middlewares;

use App\Core\ApiException;
use App\Core\Security;
use App\Core\Database;

class AuthMiddleware
{
    private static ?array $currentUser = null;
    private static bool $isImpersonated = false;
    private static ?int $operatorAdminId = null;
    private static int $authVersion = 0;

    public static function reset(): void
    {
        self::$currentUser = null;
        self::$isImpersonated = false;
        self::$operatorAdminId = null;
        self::$authVersion = 0;
    }

    public function handle(): bool
    {
        self::reset();
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
            throw new ApiException('Unauthorized: Missing or invalid token', 401, 401);
        }

        $token = $matches[1];
        $payload = Security::verifyToken($token);
        if (!$payload || empty($payload['uid'])) {
            throw new ApiException('Unauthorized: Token expired or invalid', 401, 401);
        }

        // 作用域严格校验：拒绝将 admin Token 用于普通客户路由
        if (($payload['sub_type'] ?? 'user') !== 'user') {
            throw new ApiException('Unauthorized: Invalid token scope for client portal', 401, 401);
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT id, email, phone, balance, frozen_commission, available_commission, status,auth_version FROM users WHERE id = ?");
        $stmt->execute([$payload['uid']]);
        $user = $stmt->fetch();

        if (!$user) {
            throw new ApiException('User not found', 401, 401);
        }

        if ($user['status'] === 'suspended') {
            throw new ApiException('Account suspended', 403, 403);
        }
        Security::assertUserVersion($payload,(int)$user['auth_version']);
        self::$authVersion=(int)$user['auth_version'];
        unset($user['auth_version']);

        self::$isImpersonated = !empty($payload['is_impersonated']);
        self::$operatorAdminId = $payload['operator_admin_id'] ?? null;

        // 模拟登录（Impersonation）风控：敏感读拦截 + 写操作严格白名单
        if (self::$isImpersonated) {
            $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

            // 1. 即使 GET 也严禁直接访问未脱敏实名详情或安全凭据接口
            $blockedReadPatterns = ['/verification/raw', '/security/tokens', '/realname/raw'];
            foreach ($blockedReadPatterns as $pattern) {
                if (stripos($uri, $pattern) !== false) {
                    throw new ApiException('Forbidden in impersonation mode: sensitive identity credentials access blocked', 403, 403);
                }
            }

            // 2. 写操作采用白名单制：仅允许排障必需的实例电源操作与工单回复
            if ($method !== 'GET' && $method !== 'OPTIONS' && $method !== 'HEAD') {
                $allowedWriteRegex = '#^/api/v1/(instances/\d+/power|tickets(/\d+/replies)?)$#i';
                if (!preg_match($allowedWriteRegex, $uri)) {
                    throw new ApiException(
                        'Forbidden: Impersonated sessions are read-only except for instance power actions and ticket replies',
                        403,
                        403
                    );
                }
            }
        }

        $user['is_impersonated'] = self::$isImpersonated;
        $user['operator_admin_id'] = self::$operatorAdminId;
        self::$currentUser = $user;
        return true;
    }

    public static function user(): ?array
    {
        return self::$currentUser;
    }
    public static function authVersion():int { return self::$authVersion; }

    public static function isImpersonated(): bool
    {
        return self::$isImpersonated;
    }

    public static function operatorAdminId(): ?int
    {
        return self::$operatorAdminId;
    }
}
