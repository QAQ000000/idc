<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\{ApiException,Database,RedisStore,Request,Security};
use Illuminate\Support\Facades\RateLimiter;
use PDO;

final class PasswordService
{
    public static function resetClaims(string $token): ?array
    {
        $p = Security::verifyToken($token);

        return $p && ($p['sub_type'] ?? null) === 'password_reset' && ($p['purpose'] ?? null) === 'change_account_password'
            && is_int($p['av'] ?? null) && is_string($p['email_hash'] ?? null) && is_string($p['jti'] ?? null) ? $p : null;
    }

    private static function limit(string $key, int $max, int $seconds): void
    {
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw new ApiException('Too many requests; try again later', 429, 429);
        }
        RateLimiter::hit($key, $seconds);
    }

    /** Generic response is returned by the controller for existing, missing and disabled accounts. */
    public function request(array $input): void
    {
        $email = strtolower(trim(Request::text($input, 'email', 3, 100)));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ApiException('Invalid email');
        }
        self::limit('password-forgot-ip:'.Security::requestFingerprint(Security::getClientIp()), 10, 3600);
        $key = 'password-forgot-email:'.Security::requestFingerprint($email);
        if (! (new RedisStore())->command('SET', $key.':cooldown', '1', 'NX', 'EX', '60')) {
            return;
        }
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return;
        }
        RateLimiter::hit($key, 3600);
        try {
            $settings = (new NotificationSettingsService())->load();
        } catch (\Throwable) {
            logger()->error('Password recovery mail configuration unavailable');

            return;
        }
        if (! $settings) {
            return;
        }
        Database::transaction(static function (PDO $db) use ($email): void {
            $s = $db->prepare("SELECT id,email,auth_version FROM users WHERE email=? AND status='active' FOR UPDATE");
            $s->execute([$email]);
            $user = $s->fetch();
            if (! $user) {
                return;
            }
            $nonce = bin2hex(random_bytes(16));
            $token = Security::generateToken(['uid' => (int) $user['id'], 'sub_type' => 'password_reset', 'purpose' => 'change_account_password',
                'av' => (int) $user['auth_version'], 'email_hash' => Security::requestFingerprint($email), 'jti' => $nonce], 900);
            $context = ['kind' => 'password_reset', 'encrypted_token' => Security::encrypt($token)];
            $db->prepare("INSERT INTO notification_deliveries(fingerprint,channel,user_id,target,context) VALUES(?,'mail',?,?,?)")
                ->execute(['user:'.$user['id'].':password_reset:'.$nonce, $user['id'], $email, json_encode($context, JSON_THROW_ON_ERROR)]);
        });
    }

    public function reset(array $input): void
    {
        self::limit('password-reset-ip:'.Security::requestFingerprint(Security::getClientIp()), 30, 900);
        $token = Request::text($input, 'token', 1, 1024);
        $claims = self::resetClaims($token);
        if (! $claims) {
            throw new ApiException('重置链接无效或已过期，请重新申请', 400, 400);
        }
        $hash = Security::hashPassword(Request::text($input, 'new_password', 12, 72));
        Database::transaction(static function (PDO $db) use ($claims, $hash): void {
            $s = $db->prepare('SELECT id,email,status,auth_version FROM users WHERE id=? FOR UPDATE');
            $s->execute([$claims['uid']]);
            $user = $s->fetch();
            if (! $user || $user['status'] !== 'active' || (int) $user['auth_version'] !== $claims['av']
                || ! hash_equals(Security::requestFingerprint($user['email']), $claims['email_hash']) || time() >= $claims['exp']) {
                throw new ApiException('重置链接无效或已使用，请重新申请', 400, 400);
            }
            $db->prepare('UPDATE users SET password=?,auth_version=auth_version+1 WHERE id=? AND auth_version=?')->execute([$hash, $user['id'], $claims['av']]);
        });
    }

    public function change(int $id, int $version, array $input): void
    {
        self::limit('password-change-user:'.$id, 10, 60);
        $current = Request::text($input, 'current_password', 1, 72);
        $s = Database::getInstance()->prepare('SELECT password FROM users WHERE id=?');
        $s->execute([$id]);
        $old = $s->fetchColumn();
        if (! $old || str_contains($current, "\0") || ! Security::verifyPassword($current, $old)) {
            throw new ApiException('当前密码不正确', 400, 400);
        }
        $hash = Security::hashPassword(Request::text($input, 'new_password', 12, 72));
        Database::transaction(static function (PDO $db) use ($id, $version, $old, $hash): void {
            $s = $db->prepare('SELECT password,auth_version,status FROM users WHERE id=? FOR UPDATE');
            $s->execute([$id]);
            $u = $s->fetch();
            if (! $u || $u['status'] !== 'active' || ! hash_equals($old, $u['password']) || (int) $u['auth_version'] !== $version) {
                throw new ApiException('Session or credentials changed; log in again', 401, 401);
            }
            $db->prepare('UPDATE users SET password=?,auth_version=auth_version+1 WHERE id=? AND auth_version=?')->execute([$hash, $id, $version]);
        });
    }
}
