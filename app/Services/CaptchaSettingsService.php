<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\{ApiException,Database,Request,Security};
use App\Plugins\Captcha\CaptchaDriverRegistry;
use PDO;

/** Independently versioned scenes in the existing single JSON row. */
final class CaptchaSettingsService
{
    private function authorize(PDO $db, int $actor, int $session): void
    {
        $s = $db->prepare('SELECT status,role,two_factor_secret FROM admins WHERE id=? FOR UPDATE');
        $s->execute([$actor]);
        $a = $s->fetch();
        if (! $a || $a['status'] !== 'active' || ! empty($a['two_factor_secret']) || AdminAuthService::sessionVersion($db, $actor, true) !== $session) {
            throw new ApiException('Administrator session revoked; log in again', 401, 401);
        }
        if ($a['role'] !== 'super_admin') {
            throw new ApiException('Active super_admin required', 403, 403);
        }
    }

    private function sceneId(string $scene): int
    {
        $id = array_search($scene, CaptchaService::SCENES, true);
        if ($id === false) {
            throw new ApiException('Invalid captcha scene');
        }

        return $id + 1;
    }

    private function policy(array $settings, string $scene): array
    {
        $policy = array_key_exists($scene, $settings) ? CaptchaService::normalize($settings[$scene]) : CaptchaService::DEFAULT_POLICY;
        if ($policy['driver'] !== 'builtin_image') {
            throw new \RuntimeException('This release only manages the installed local image policy');
        }
        $driver = CaptchaDriverRegistry::installed()->get($policy['driver']);
        if ($driver->getType() !== 'image') {
            throw new \RuntimeException('Local image driver unavailable');
        }

        return $policy;
    }

    private function version(PDO $db, array $settings, string $scene): string
    {
        $s = $db->prepare("SELECT id FROM admin_audit_logs WHERE target_type='captcha_scene' AND target_id=? AND action='captcha_configure' ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $s->execute([$this->sceneId($scene)]);

        return Security::requestFingerprint(json_encode([$scene, $settings[$scene] ?? null, (int) ($s->fetchColumn() ?: 0)], JSON_THROW_ON_ERROR));
    }

    public function show(int $actor, int $session): array
    {
        return Database::transaction(function (PDO $db) use ($actor, $session): array {
            $this->authorize($db, $actor, $session);
            $settings = CaptchaService::settings(CaptchaService::settingsRow($db, true));
            $scenes = [];
            foreach (CaptchaService::SCENES as $scene) {
                $scenes[$scene] = $this->policy($settings, $scene) + ['configured' => array_key_exists($scene, $settings), 'version' => $this->version($db, $settings, $scene)];
            }

            return ['scenes' => $scenes];
        }, 3);
    }

    public function save(int $actor, int $session, string $scene, array $input): void
    {
        $id = $this->sceneId($scene);
        Database::transaction(function (PDO $db) use ($actor, $session, $scene, $input, $id): void {
            $this->authorize($db, $actor, $session);
            if (array_diff(array_keys($input), ['enabled', 'driver', 'mode', 'expected_version']) || ! is_bool($input['enabled'] ?? null) || ($input['driver'] ?? null) !== 'builtin_image' || ($input['mode'] ?? null) !== 'always') {
                throw new ApiException('Only the installed image captcha and always mode are supported');
            }
            $row = CaptchaService::settingsRow($db, true);
            $settings = CaptchaService::settings($row);
            // Validate all existing scenes before preserving them, never silently repair an invalid off policy.
            foreach (CaptchaService::SCENES as $name) {
                $this->policy($settings, $name);
            }
            if (! hash_equals($this->version($db, $settings, $scene), Request::text($input, 'expected_version', 64, 64))) {
                throw new ApiException('验证码策略已变更，请核对后重新确认', 409, 409);
            }
            $before = $this->policy($settings, $scene);
            $after = ['enabled' => $input['enabled'], 'mode' => 'always', 'driver' => 'builtin_image'];
            $settings[$scene] = $after;
            $db->prepare("INSERT INTO system_settings(`key`,`group`,value,updated_by) VALUES('captcha_scenes','captcha',?,?) ON DUPLICATE KEY UPDATE value=VALUES(value),updated_by=VALUES(updated_by)")
                ->execute([json_encode($settings, JSON_THROW_ON_ERROR), $actor]);
            $db->prepare("INSERT INTO admin_audit_logs(admin_id,action,target_type,target_id,context,ip) VALUES(?,'captcha_configure','captcha_scene',?,?,?)")
                ->execute([$actor, $id, json_encode(['scene' => $scene, 'before' => $before, 'after' => $after], JSON_THROW_ON_ERROR), Security::getClientIp()]);
        }, 5);
    }
}
