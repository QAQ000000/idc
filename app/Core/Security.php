<?php

namespace App\Core;

class Security
{
    private static function getAppKey(): string
    {
        $key = getenv('APP_KEY');
        if ($key === false || $key === '') {
            // 主密钥只允许来自环境变量；缺少时必须拒绝启动，
            // 严禁静默降级到任何代码内置的固定密钥（等于加密形同虚设）。
            throw new \RuntimeException(
                'Missing required environment variable APP_KEY (supports "base64:<key>" or plain secret). Refusing to run with a fallback key.'
            );
        }
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded === false || strlen($decoded) !== 32) {
                throw new \RuntimeException('APP_KEY must decode to exactly 32 bytes');
            }
            return $decoded;
        }
        return hash('sha256', $key, true);
    }

    /**
     * 密码哈希 (Bcrypt)
     */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * 密码校验 (兼容旧系统的 MD5，并在匹配成功后支持平滑升级为 Bcrypt)
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        // 标准 Bcrypt/Argon2
        if (password_verify($password, $hash)) {
            return true;
        }

        // 兼容旧魔方 MD5 加密 (32位小写)
        if (strlen($hash) === 32 && md5($password) === $hash) {
            return true;
        }

        return false;
    }

    /**
     * AES-256-GCM 敏感数据加密 (信封加密)
     */
    public static function encrypt(string $plainText): string
    {
        $key = self::getAppKey();
        $iv = random_bytes(12);
        $tag = '';
        $cipherText = openssl_encrypt($plainText, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipherText === false) {
            throw new \RuntimeException('Encryption failed');
        }

        return base64_encode($iv . $tag . $cipherText);
    }

    /**
     * AES-256-GCM 敏感数据解密
     */
    public static function decrypt(string $encoded): ?string
    {
        $data = base64_decode($encoded, true);
        if ($data === false || strlen($data) < 28) {
            return null;
        }

        $key = self::getAppKey();
        $iv = substr($data, 0, 12);
        $tag = substr($data, 12, 16);
        $cipherText = substr($data, 28);

        $decrypted = openssl_decrypt($cipherText, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $decrypted !== false ? $decrypted : null;
    }

    /**
     * 生成安全 Bearer Token (轻量自包含签名 Token, 默认 TTL 2 小时)
     */
    public static function generateToken(array $payload, int $ttl = 7200): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        if (empty($payload['sub_type'])) {
            $payload['sub_type'] = 'user'; // 'user' | 'admin'
        }
        $payload['iat'] = time();
        $payload['exp'] = time() + $ttl;

        $b64Header = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
        $b64Payload = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');

        $signature = hash_hmac('sha256', "{$b64Header}.{$b64Payload}", self::getAppKey(), true);
        $b64Signature = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        return "{$b64Header}.{$b64Payload}.{$b64Signature}";
    }

    /**
     * 校验并解码 Bearer Token
     */
    public static function verifyToken(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$b64Header, $b64Payload, $b64Signature] = $parts;
        $expectedSignature = rtrim(strtr(base64_encode(hash_hmac('sha256', "{$b64Header}.{$b64Payload}", self::getAppKey(), true)), '+/', '-_'), '=');

        if (!hash_equals($expectedSignature, $b64Signature)) {
            return null;
        }

        $header = json_decode(base64_decode(strtr($b64Header, '-_', '+/'), true) ?: '', true);
        $payload = json_decode(base64_decode(strtr($b64Payload, '-_', '+/'), true) ?: '', true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256' || ($header['typ'] ?? '') !== 'JWT'
            || !is_array($payload) || !is_int($payload['exp'] ?? null) || time() >= $payload['exp']
            || !is_int($payload['uid'] ?? null) || $payload['uid'] < 1) {
            return null;
        }

        return $payload;
    }

    /**
     * 获取客户端真实 IP
     */
    public static function getClientIp(): string
    {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}
