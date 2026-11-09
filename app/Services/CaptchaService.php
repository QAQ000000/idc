<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\{ApiException, Database};
use App\Plugins\Captcha\{CaptchaDriverInterface, CaptchaDriverRegistry};
use PDO;

final class CaptchaService
{
    public const SCENES = ['login','register','password_reset'];
    public const DEFAULT_POLICY = ['enabled'=>true,'mode'=>'always','driver'=>'builtin_image'];
    private readonly CaptchaDriverRegistry $drivers;

    public function __construct(?CaptchaDriverRegistry $drivers = null)
    {
        $this->drivers = $drivers ?? CaptchaDriverRegistry::installed();
    }

    public static function settingsRow(PDO $db, bool $lock=false): ?array
    {
        $s=$db->prepare('SELECT value FROM system_settings WHERE `key`=?'.($lock?' FOR UPDATE':''));$s->execute(['captcha_scenes']);return $s->fetch()?:null;
    }

    public static function settings(?array $row): array
    {
        if($row===null){return [];}
        $object=json_decode($row['value'],false,32,JSON_THROW_ON_ERROR);
        if(!$object instanceof \stdClass||array_diff(array_keys((array)$object),self::SCENES)){throw new \RuntimeException('Invalid captcha settings');}
        return json_decode($row['value'],true,32,JSON_THROW_ON_ERROR);
    }

    public static function normalize(mixed $policy): array
    {
        if(!is_array($policy)||!is_bool($policy['enabled']??null)||array_diff(array_keys($policy),['enabled','mode','driver'])){throw new \RuntimeException('Invalid captcha policy');}
        $policy=array_replace(self::DEFAULT_POLICY,$policy);
        if(!is_string($policy['driver'])||$policy['driver']===''||$policy['mode']!=='always'){throw new \RuntimeException('Captcha driver or mode is not supported');}
        return $policy;
    }

    private function policy(string $scene): array
    {
        if (!in_array($scene, self::SCENES, true)) {
            throw new ApiException('Invalid captcha scene');
        }
        $settings=self::settings(self::settingsRow(Database::getInstance()));
        return array_key_exists($scene,$settings)?self::normalize($settings[$scene]):self::DEFAULT_POLICY;
    }

    private function driver(array $policy): CaptchaDriverInterface
    {
        if (!is_string($policy['driver'] ?? null) || ($policy['mode'] ?? 'always') !== 'always') {
            throw new \RuntimeException('Captcha driver or mode is not supported');
        }
        return $this->drivers->get($policy['driver']);
    }

    public function config(string $scene, string $ip): array
    {
        $policy = $this->policy($scene);
        $driver = $this->driver($policy); // Invalid/unavailable drivers fail closed, including disabled malformed policies.
        if (!$policy['enabled']) {
            return ['enabled' => false];
        }
        return ['enabled' => true, 'driver' => $driver->getDriverId(), 'type' => $driver->getType()] + $driver->generateChallenge($scene, $ip);
    }

    public function verify(string $scene, array $payload, string $ip): void
    {
        $policy = $this->policy($scene);
        $driver = $this->driver($policy);
        if (!$policy['enabled']) {
            return;
        }
        $payload['scene'] = $scene;
        if (!$driver->verify($payload, $ip)->success) {
            throw new ApiException('Captcha invalid or expired', 422, 422);
        }
    }
}
