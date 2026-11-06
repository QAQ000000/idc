<?php

namespace App\Middlewares;

use App\Core\Response;
use App\Core\Security;
use App\Core\Database;

class AuthMiddleware
{
    private static ?array $currentUser = null;

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

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT id, email, phone, balance, frozen_commission, available_commission, role, status FROM users WHERE id = ?");
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

        self::$currentUser = $user;
        return true;
    }

    public static function user(): ?array
    {
        return self::$currentUser;
    }
}
