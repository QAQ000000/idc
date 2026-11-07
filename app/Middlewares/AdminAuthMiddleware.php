<?php

namespace App\Middlewares;

use App\Core\ApiException;
use App\Core\Security;
use App\Core\Database;

class AdminAuthMiddleware
{
    private static ?array $currentAdmin = null;

    public static function reset(): void
    {
        self::$currentAdmin = null;
    }

    public function handle(): bool
    {
        self::reset();
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
            throw new ApiException('Unauthorized: Missing or invalid admin token', 401, 401);
        }

        $token = $matches[1];
        $payload = Security::verifyToken($token);
        if (!$payload || empty($payload['uid']) || ($payload['sub_type'] ?? '') !== 'admin') {
            throw new ApiException('Unauthorized: Invalid admin credentials or token expired', 401, 401);
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT id, username, email, role, status FROM admins WHERE id = ?");
        $stmt->execute([$payload['uid']]);
        $admin = $stmt->fetch();

        if (!$admin) {
            throw new ApiException('Admin user not found', 401, 401);
        }

        if ($admin['status'] !== 'active') {
            throw new ApiException('Admin account disabled', 403, 403);
        }

        self::$currentAdmin = $admin;
        return true;
    }

    public static function admin(): ?array
    {
        return self::$currentAdmin;
    }
}
