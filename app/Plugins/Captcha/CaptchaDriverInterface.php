<?php
declare(strict_types=1);
namespace App\Plugins\Captcha;

interface CaptchaDriverInterface
{
    public function getDriverId(): string;
    public function getName(): string;
    public function getType(): string;
    public function getConfigSchema(): array;
    public function generateChallenge(string $scene, string $clientIp): array;
    public function verify(array $payload, string $clientIp): CaptchaVerifyResult;
}
