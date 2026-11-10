<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\{ApiException, Database, Request, Security};
use App\Plugins\Payments\PaymentRegistry;
use PDO;

/** Single installed epay channel. Merchant identity is immutable after any checkout attempt. */
final class PaymentSettingsService
{
    private const FIELDS = ['enabled', 'endpoint', 'pid', 'type', 'notify_url', 'return_url'];

    private static function authorize(PDO $db, int $admin): void
    {
        $s = $db->prepare("SELECT id FROM admins WHERE id=? AND role='super_admin' AND status='active' FOR UPDATE");
        $s->execute([$admin]);
        if (! $s->fetchColumn()) {
            throw new ApiException('Active super_admin required', 403, 403);
        }
    }

    public static function row(PDO $db, string $channel, bool $lock = false): ?array
    {
        $s = $db->prepare('SELECT value,encrypted_credentials FROM system_settings WHERE `key`=?'.($lock ? ' FOR UPDATE' : ''));
        $s->execute(['payment.'.$channel]);

        return $s->fetch() ?: null;
    }

    private function hasAttempts(PDO $db): bool
    {
        // Current read, including an attempt committed while this transaction waited for the settings lock.
        return (bool) $db->query("SELECT id FROM payment_records WHERE channel='epay' LIMIT 1 FOR UPDATE")->fetchColumn();
    }

    private function version(PDO $db, ?array $row, bool $locked): string
    {
        $audit = $db->query("SELECT COALESCE(MAX(id),0) FROM admin_audit_logs WHERE action='payment_configure' AND target_type='payment'")->fetchColumn();

        return Security::requestFingerprint(json_encode([$row, $locked, $audit], JSON_THROW_ON_ERROR));
    }

    private function publicConfig(?array $row): array
    {
        $public = array_replace(['enabled' => false, 'endpoint' => '', 'pid' => '', 'type' => 'alipay', 'notify_url' => '', 'return_url' => ''],
            array_intersect_key($row ? json_decode($row['value'], true, 32, JSON_THROW_ON_ERROR) : [], array_flip(self::FIELDS)));
        if (is_int($public['pid'])) {
            $public['pid'] = (string) $public['pid'];
        } // Legacy CLI JSON may store numeric PIDs.

        return $public;
    }

    private function secret(?array $row): string
    {
        if (! $row) {
            return '';
        }$plain = Security::decrypt($row['encrypted_credentials'] ?? '');
        if ($plain === null) {
            throw new \RuntimeException('Payment credentials unavailable');
        }

        return json_decode($plain, true, 32, JSON_THROW_ON_ERROR)['key'] ?? '';
    }

    public function show(int $admin): array
    {
        return Database::transaction(function (PDO $db) use ($admin): array {
            self::authorize($db, $admin);
            $row = self::row($db, 'epay', true);
            $locked = $this->hasAttempts($db);

            return $this->publicConfig($row) + ['configured' => $row !== null, 'key_configured' => $this->secret($row) !== '',
                'identity_locked' => $locked, 'version' => $this->version($db, $row, $locked)];
        }, 3);
    }

    private function validate(array $public, string $key): void
    {
        PaymentRegistry::installed()->get('epay');
        if (! is_bool($public['enabled'] ?? null) || ! is_string($public['pid'] ?? null) || ! preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $public['pid'])
            || ! in_array($public['type'] ?? null, ['alipay', 'wxpay', 'qqpay'], true) || $key === '' || strlen($key) > 4096
            || preg_match('/[\x00-\x1f\x7f]/', $key)) {
            throw new ApiException('Invalid payment configuration');
        }
        foreach (['endpoint', 'notify_url', 'return_url'] as $field) {
            $url = $public[$field] ?? null;
            $parts = is_string($url) ? parse_url($url) : false;
            if (! is_string($url) || strlen($url) > 255 || ! filter_var($url, FILTER_VALIDATE_URL) || ! is_array($parts)
                || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['query']) || isset($parts['fragment']) || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
                || (! app()->environment(['local', 'test']) && $parts['scheme'] !== 'https')) {
                throw new ApiException('Invalid payment URL');
            }
            if ($field === 'return_url' && ! in_array($parts['path'] ?? '', ['', '/'], true)) {
                throw new ApiException('Return URL must be a site origin');
            }
        }
    }

    public function save(int $admin, array $input): void
    {
        Database::transaction(function (PDO $db) use ($admin, $input): void {
            self::authorize($db, $admin);
            if (array_diff(array_keys($input), [...self::FIELDS, 'expected_version', 'key_action', 'key'])
                || array_diff(self::FIELDS, array_keys($input)) || ! in_array($input['key_action'] ?? null, ['keep', 'replace'], true)
                || ($input['key_action'] === 'keep' && array_key_exists('key', $input))
                || ($input['key_action'] === 'replace' && ! is_string($input['key'] ?? null))) {
                throw new ApiException('Explicit key replacement or retention required');
            }
            $row = self::row($db, 'epay', true);
            $locked = $this->hasAttempts($db);
            if (! hash_equals($this->version($db, $row, $locked), Request::text($input, 'expected_version', 64, 64))) {
                throw new ApiException('Payment configuration changed; reload and review again', 409, 409);
            }
            $old = $this->publicConfig($row);
            $public = array_intersect_key($input, array_flip(self::FIELDS));
            if ($locked && (! $row || $public['endpoint'] !== $old['endpoint'] || $public['pid'] !== $old['pid'] || $input['key_action'] !== 'keep')) {
                throw new ApiException('Merchant identity is locked by historical payment attempts', 409, 409);
            }
            $key = $input['key_action'] === 'replace' ? $input['key'] : $this->secret($row);
            $this->validate($public, $key);
            // Keep preserves the exact ciphertext; no plaintext or secret-derived hash in API/audit.
            $encrypted = $input['key_action'] === 'keep' ? $row['encrypted_credentials'] : Security::encrypt(json_encode(['key' => $key], JSON_THROW_ON_ERROR));
            $db->prepare("INSERT INTO system_settings(`key`,`group`,value,encrypted_credentials,updated_by) VALUES('payment.epay','payment',?,?,?)
                ON DUPLICATE KEY UPDATE value=VALUES(value),encrypted_credentials=VALUES(encrypted_credentials),updated_by=VALUES(updated_by)")
                ->execute([json_encode($public, JSON_THROW_ON_ERROR), $encrypted, $admin]);
            $db->prepare("INSERT INTO admin_audit_logs(admin_id,action,target_type,target_id,context,ip) VALUES(?,'payment_configure','payment',0,?,?)")
                ->execute([$admin, json_encode(['before' => $old, 'after' => $public, 'key_action' => $input['key_action'], 'identity_locked' => $locked], JSON_THROW_ON_ERROR), Security::getClientIp()]);
        }, 5);
    }

    /** Existing CLI installer is initial-only; same validation and lock, no replacement escape hatch. */
    public function installInitial(PDO $db, array $input): void
    {
        if (PHP_SAPI !== 'cli' || ($input['driver'] ?? null) !== 'epay') {
            throw new ApiException('CLI epay initialization only');
        }
        $public = array_intersect_key($input, array_flip(self::FIELDS));
        $public['enabled'] = true;
        $public['type'] ??= 'alipay';
        if (is_int($public['pid'] ?? null)) {
            $public['pid'] = (string) $public['pid'];
        }
        $key = $input['credentials']['key'] ?? '';
        if (! is_string($key)) {
            throw new ApiException('Invalid payment key');
        }$this->validate($public, $key);
        if (self::row($db, 'epay', true) || $this->hasAttempts($db)) {
            throw new ApiException('Payment configuration already exists or has historical attempts', 409, 409);
        }
        $db->prepare("INSERT INTO system_settings(`key`,`group`,value,encrypted_credentials) VALUES('payment.epay','payment',?,?)")
            ->execute([json_encode($public, JSON_THROW_ON_ERROR), Security::encrypt(json_encode(['key' => $key], JSON_THROW_ON_ERROR))]);
    }
}
