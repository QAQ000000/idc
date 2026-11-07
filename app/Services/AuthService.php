<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\{ApiException, Database, Request, Security};
use PDO;

final class AuthService
{
    public function register(array $input): array
    {
        $email = $this->email($input);
        $password = Request::text($input, 'password', 12, 72);
        $hash = Security::hashPassword($password);
        return Database::transaction(function (PDO $db) use ($email, $hash, $input): array {
            $affiliate = null;
            if (isset($input['affiliate_code']) && $input['affiliate_code'] !== '') {
                $code = Request::text($input, 'affiliate_code', 1, 32);
                $stmt = $db->prepare("SELECT id FROM users WHERE affiliate_code = ? AND status = 'active'");
                $stmt->execute([$code]);
                $affiliate = $stmt->fetchColumn();
                if (!$affiliate) {
                    throw new ApiException('Invalid affiliate code');
                }
            }
            try {
                $db->prepare('INSERT INTO users (email, password, affiliate_id, affiliate_code) VALUES (?, ?, ?, ?)')
                    ->execute([$email, $hash, $affiliate, bin2hex(random_bytes(12))]);
            } catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? null) === 1062) {
                    throw new ApiException('Email already registered', 409, 409);
                }
                throw $e;
            }
            return $this->session($db, (int)$db->lastInsertId());
        });
    }

    public function login(array $input): array
    {
        $email = $this->email($input);
        $password = Request::text($input, 'password', 1, 72);
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT id, password, status FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $snapshot = $stmt->fetch();
        // 昂贵哈希在行锁外完成，不存在账号仍执行相同成本校验。
        $hash = $snapshot['password'] ?? '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        if (str_contains($password, "\0") || !Security::verifyPassword($password, $hash) || !$snapshot) {
            throw new ApiException('Invalid email or password', 401, 401);
        }
        $newHash = password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]) ? Security::hashPassword($password) : null;
        return Database::transaction(function (PDO $db) use ($snapshot, $newHash): array {
            $stmt = $db->prepare('SELECT id, password, status FROM users WHERE id = ? FOR UPDATE');
            $stmt->execute([$snapshot['id']]);
            $user = $stmt->fetch();
            if (!$user || !hash_equals($snapshot['password'], $user['password'])) {
                throw new ApiException('Account credentials changed; log in again', 409, 409);
            }
            if ($user['status'] !== 'active') {
                throw new ApiException('Account suspended', 403, 403);
            }
            if ($newHash !== null) {
                $db->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([$newHash, $user['id']]);
            }
            $db->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP(), last_login_ip = ? WHERE id = ?')
                ->execute([Security::getClientIp(), $user['id']]);
            return $this->session($db, (int)$user['id']);
        });
    }

    private function email(array $input): string
    {
        $email = strtolower(trim(Request::text($input, 'email', 3, 100)));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ApiException('Invalid email');
        }
        return $email;
    }

    private function session(PDO $db, int $id): array
    {
        $stmt = $db->prepare('SELECT id, email, balance FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        $user['role'] = 'client';
        return ['token' => Security::generateToken(['uid' => $id, 'sub_type' => 'user']), 'user' => $user];
    }
}
