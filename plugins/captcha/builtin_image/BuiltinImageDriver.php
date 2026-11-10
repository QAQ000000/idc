<?php

declare(strict_types=1);

namespace Plugins\captcha\builtin_image;

use App\Core\RedisStore;
use App\Plugins\Captcha\{CaptchaDriverInterface, CaptchaVerifyResult};

final class BuiltinImageDriver implements CaptchaDriverInterface
{
    public function getDriverId(): string
    {
        return 'builtin_image';
    }

    public function getName(): string
    {
        return '本地图片验证码';
    }

    public function getType(): string
    {
        return 'image';
    }

    public function getConfigSchema(): array
    {
        return [];
    }

    public function generateChallenge(string $scene, string $clientIp): array
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $answer = '';
        for ($i = 0; $i < 5; $i++) {
            $answer .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $id = bin2hex(random_bytes(24));
        $image = imagecreatetruecolor(160, 50);
        imagefill($image, 0, 0, imagecolorallocate($image, 245, 248, 252));
        for ($i = 0; $i < 12; $i++) {
            imageline($image, random_int(0, 159), random_int(0, 49), random_int(0, 159), random_int(0, 49), imagecolorallocate($image, random_int(100, 200), random_int(100, 200), random_int(100, 200)));
        }
        for ($i = 0; $i < 5; $i++) {
            imagestring($image, 5, 15 + 27 * $i, random_int(10, 25), $answer[$i], imagecolorallocate($image, 25, 50, 85));
        }
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);
        (new RedisStore())->command('SET', 'jianyun:captcha:'.$id, json_encode([
            'hash' => hash('sha256', $answer), 'scene' => $scene, 'ip' => $clientIp,
        ], JSON_THROW_ON_ERROR), 'EX', '300');

        return ['challenge_id' => $id, 'img_base64' => 'data:image/png;base64,'.base64_encode($png), 'expires_in' => 300];
    }

    public function verify(array $payload, string $clientIp): CaptchaVerifyResult
    {
        $id = $payload['challenge_id'] ?? null;
        $answer = $payload['answer'] ?? null;
        if (! is_string($id) || ! preg_match('/^[a-f0-9]{48}$/D', $id) || ! is_string($answer) || strlen($answer) > 20) {
            return new CaptchaVerifyResult(false);
        }
        // Lua 原子取出并删除，错误答案同样消耗挑战；兼容 Redis 5+。
        $raw = (new RedisStore())->command('EVAL', "local v=redis.call('GET',KEYS[1]); redis.call('DEL',KEYS[1]); return v", '1', 'jianyun:captcha:'.$id);
        $stored = $raw ? json_decode($raw, true, 16, JSON_THROW_ON_ERROR) : null;

        return new CaptchaVerifyResult($stored && ($stored['scene'] === ($payload['scene'] ?? null))
            && $stored['ip'] === $clientIp && hash_equals($stored['hash'], hash('sha256', strtoupper($answer))));
    }
}
