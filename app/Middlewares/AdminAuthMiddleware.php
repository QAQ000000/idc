<?php

namespace App\Middlewares;

use App\Core\Response;
use App\Core\Security;
use App\Core\Database;

class AdminAuthMiddleware
{
    private static ?array $currentAdmin = null;

    public function handle(): bool
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
            Response::error('Unauthorized: Missing or invalid admin token', 401, 401);
            return false;
        }

        $token = $matches[1];
        $payload = Security::verifyToken($token);
        if (!$payload || empty($payload['uid']) || ($payload['sub_type'] ?? '') !== 'admin') {
            Response::error('Unauthorized: Invalid admin credentials or token expired', 401, 401);
            return false;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT id, username, email, role, status FROM admins WHERE id = ?");
        $stmt->execute([$payload['uid']]);
        $admin = $stmt->fetch();

        if (!$admin) {
            Response::error('Admin user not found', 401, 401);
            return false;
        }

        if ($admin['status'] !== 'active') {
            Response::error('Admin account disabled', 403, 403);
            return false;
        }

        self::$currentAdmin = $admin;
        return true;
    }

    public static function admin(): ?array
    {
        return self::$currentAdmin;
    }
}
