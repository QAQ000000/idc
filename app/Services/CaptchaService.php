<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\{ApiException, Database};
use App\Plugins\Captcha\CaptchaDriverInterface;
use Plugins\captcha\builtin_image\BuiltinImageDriver;

final class CaptchaService
{
    private function policy(string $scene): array
    {
        if (!in_array($scene, ['login', 'register', 'password_reset'], true)) {
            throw new ApiException('Invalid captcha scene');
        }
        $stmt = Database::getInstance()->prepare('SELECT value FROM system_settings WHERE `key` = ?');
        $stmt->execute(['captcha_scenes']);
        $value = $stmt->fetchColumn();
        $settings = $value ? json_decode($value, true, 32, JSON_THROW_ON_ERROR) : [];
        $policy = $settings[$scene] ?? ['enabled' => true, 'mode' => 'always', 'driver' => 'builtin_image'];
        if (!is_array($policy) || !is_bool($policy['enabled'] ?? null)) {
            throw new \RuntimeException('Invalid captcha policy');
        }
        return $policy;
    }

    private function driver(array $policy): CaptchaDriverInterface
    {
        if (($policy['driver'] ?? '') !== 'builtin_image' || ($policy['mode'] ?? 'always') !== 'always') {
            throw new \RuntimeException('Captcha driver or mode is not installed');
        }
        return new BuiltinImageDriver();
    }

    public function config(string $scene, string $ip): array
    {
        $policy = $this->policy($scene);
        if (!$policy['enabled']) {
            return ['enabled' => false];
        }
        $driver = $this->driver($policy);
        return ['enabled' => true, 'driver' => $driver->getDriverId(), 'type' => $driver->getType()] + $driver->generateChallenge($scene, $ip);
    }

    public function verify(string $scene, array $payload, string $ip): void
    {
        $policy = $this->policy($scene);
        if (!$policy['enabled']) {
            return;
        }
        $payload['scene'] = $scene;
        if (!$this->driver($policy)->verify($payload, $ip)->success) {
            throw new ApiException('Captcha invalid or expired', 422, 422);
        }
    }
}
