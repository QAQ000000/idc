<?php

namespace App\Middlewares;

use App\Core\Response;
use App\Core\Security;
use App\Core\Database;

class AuthMiddleware
{
    private static ?array $currentUser = null;
    private static bool $isImpersonated = false;
    private static ?int $operatorAdminId = null;

    public function handle(): bool
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
            Response::error('Unauthorized: Missing or invalid token', 401, 401);
            return false;
        }

        $token = $matches[1];
        $payload = Security::verifyToken($token);
        if (!$payload || empty($payload['uid'])) {
            Response::error('Unauthorized: Token expired or invalid', 401, 401);
            return false;
        }

        // 作用域严格校验：拒绝将 admin Token 用于普通客户路由
        if (($payload['sub_type'] ?? 'user') !== 'user') {
            Response::error('Unauthorized: Invalid token scope for client portal', 401, 401);
            return false;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT id, email, phone, balance, frozen_commission, available_commission, status FROM users WHERE id = ?");
        $stmt->execute([$payload['uid']]);
        $user = $stmt->fetch();

        if (!$user) {
            Response::error('User not found', 401, 401);
            return false;
        }

        if ($user['status'] === 'suspended') {
            Response::error('Account suspended', 403, 403);
            return false;
        }

        self::$isImpersonated = !empty($payload['is_impersonated']);
        self::$operatorAdminId = $payload['operator_admin_id'] ?? null;

        // 模拟登录（Impersonation）写操作与敏感凭据拦截
        if (self::$isImpersonated) {
            $uri = $_SERVER['REQUEST_URI'] ?? '';
            $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
            $blockedPatterns = [
                '/password',
                '/email',
                '/phone',
                '/withdraw',
                '/verification',
                '/realname',
                '/security',
            ];

            foreach ($blockedPatterns as $pattern) {
                if (stripos($uri, $pattern) !== false && $method !== 'GET') {
                    Response::error(
                        'Forbidden: Impersonated sessions are strictly prohibited from modifying security credentials or requesting withdrawals',
                        403,
                        403
                    );
                    return false;
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

    public static function isImpersonated(): bool
    {
        return self::$isImpersonated;
    }

    public static function operatorAdminId(): ?int
    {
        return self::$operatorAdminId;
    }
}
