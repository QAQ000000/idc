<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\{ApiException, Database, Request, Security};
use App\Plugins\Notifications\NotificationRegistry;
use Illuminate\Support\Facades\Validator;
use PDO;

final class NotificationSettingsService
{
    public function __construct(private readonly ?NotificationRegistry $registry = null) {}

    /** Shared configuration writer for CLI and the versioned admin entry point. */
    public function configure(int $admin, array $input, ?string $passwordAction = null): void
    {
        $rules = ['enabled' => 'required|boolean', 'task_alerts' => 'required|boolean', 'due_reminders' => 'required|boolean', 'site_url' => 'required|url:http,https|max:255',
            'admin_ids' => 'required|array|min:1|max:20', 'admin_ids.*' => 'required|integer|min:1|distinct', 'config' => 'required|array',
            'subject_prefix' => ['sometimes', 'string', 'max:40', 'not_regex:/[\x00-\x1f\x7f]/']];
        if (Validator::make($input, $rules)->fails() || ! is_bool($input['enabled'] ?? null)
            || ! is_bool($input['task_alerts'] ?? null) || ! is_bool($input['due_reminders'] ?? null)) {
            throw new ApiException('Invalid notification settings');
        }
        $url = parse_url($input['site_url']);
        if (isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || ! in_array($url['path'] ?? '', ['', '/'], true)
            || (! app()->environment(['local', 'test']) && ($url['scheme'] ?? '') !== 'https')) {
            throw new ApiException('Notification site URL must be a trusted site origin');
        }
        $driver = ($this->registry ?? NotificationRegistry::installed())->get('mail');
        $schema = $driver->getConfigSchema();
        if (array_diff(array_keys($input['config']), array_keys($schema)) || Validator::make($input['config'], $schema)->fails()) {
            throw new ApiException('Invalid private mail configuration');
        }
        $public = ['enabled' => $input['enabled'], 'task_alerts' => $input['task_alerts'], 'due_reminders' => $input['due_reminders'], 'site_url' => rtrim($input['site_url'], '/'),
            'admin_ids' => array_map('intval', array_values($input['admin_ids'])), 'subject_prefix' => $input['subject_prefix'] ?? ''];
        $encrypted = Security::encrypt(json_encode($input['config'], JSON_THROW_ON_ERROR));
        Database::transaction(static function (PDO $db) use ($admin, $public, $encrypted, $input, $passwordAction): void {
            self::authorize($db, $admin);
            foreach ($public['admin_ids'] as $recipient) {
                $s = $db->prepare("SELECT email FROM admins WHERE id=? AND status='active' FOR UPDATE");
                $s->execute([$recipient]);
                if (! filter_var($s->fetchColumn(), FILTER_VALIDATE_EMAIL)) {
                    throw new ApiException('Notification recipient must be an active administrator');
                }
            }
            $db->prepare("INSERT INTO system_settings(`key`,`group`,value,encrypted_credentials,updated_by) VALUES('notify.mail','notification',?,?,?)
                ON DUPLICATE KEY UPDATE value=VALUES(value),encrypted_credentials=VALUES(encrypted_credentials),updated_by=VALUES(updated_by)")
                ->execute([json_encode($public, JSON_THROW_ON_ERROR), $encrypted, $admin]);
            $db->prepare("INSERT INTO admin_audit_logs(admin_id,action,target_type,target_id,context,ip) VALUES(?,'notification_configure','notification',0,?,?)")
                ->execute([$admin, json_encode($public + ['source' => $passwordAction === null ? 'cli' : 'admin_web', 'password_action' => $passwordAction ?? 'replace', 'smtp' => array_intersect_key($input['config'], array_flip(['host', 'port', 'security', 'from', 'from_name'])), 'password_configured' => is_string($input['config']['password'] ?? null) && $input['config']['password'] !== ''], JSON_THROW_ON_ERROR), Security::getClientIp()]);
        }, 3);
    }

    public static function authorize(PDO $db, int $admin): void
    {
        $s = $db->prepare("SELECT id FROM admins WHERE id=? AND role='super_admin' AND status='active' FOR UPDATE");
        $s->execute([$admin]);
        if (! $s->fetchColumn()) {
            throw new ApiException('Active super_admin required', 403, 403);
        }
    }

    /** An explicit commit starts a new subscription period; never sweep historical ticket messages. */
    public function configureTickets(int $admin, array $input): array
    {
        if (array_diff(array_keys($input), ['enabled', 'admin_ids']) || ! is_bool($input['enabled'] ?? null)
            || Validator::make($input, ['admin_ids' => 'present|array|max:20', 'admin_ids.*' => 'required|integer|min:1|distinct'])->fails()
            || ($input['enabled'] && ! $input['admin_ids'])) {
            throw new ApiException('Explicit ticket mail switch and administrator recipients required');
        }
        $recipients = array_map('intval', array_values($input['admin_ids']));
        sort($recipients);

        return Database::transaction(static function (PDO $db) use ($admin, $input, $recipients): array {
            self::authorize($db, $admin);
            foreach ($recipients as $id) {
                $s = $db->prepare("SELECT email FROM admins WHERE id=? AND status='active' FOR UPDATE");
                $s->execute([$id]);
                if (! filter_var($s->fetchColumn(), FILTER_VALIDATE_EMAIL)) {
                    throw new ApiException('Ticket recipient must be an active administrator');
                }
            }
            $s = $db->query("SELECT value FROM system_settings WHERE `key`='notify.mail' FOR UPDATE");
            $row = $s->fetchColumn();
            if ($input['enabled'] && (! $row || (json_decode($row, true, 32, JSON_THROW_ON_ERROR)['enabled'] ?? false) !== true)) {
                throw new ApiException('Configure and enable the mail channel before ticket subscriptions');
            }
            $value = ['enabled' => $input['enabled'], 'admin_ids' => $recipients, 'activation' => bin2hex(random_bytes(16)),
                'activated_at' => $db->query('SELECT UTC_TIMESTAMP()')->fetchColumn()];
            $db->prepare("INSERT INTO system_settings(`key`,`group`,value,updated_by) VALUES('notify.ticket_mail','notification',?,?)
                ON DUPLICATE KEY UPDATE value=VALUES(value),updated_by=VALUES(updated_by)")->execute([json_encode($value, JSON_THROW_ON_ERROR), $admin]);
            $db->prepare("INSERT INTO admin_audit_logs(admin_id,action,target_type,target_id,context,ip) VALUES(?,'ticket_notification_configure','notification',0,?,?)")
                ->execute([$admin, json_encode($value, JSON_THROW_ON_ERROR), Security::getClientIp()]);

            return $value;
        }, 3);
    }

    private function row(PDO $db, string $key): ?array
    {
        $s = $db->prepare('SELECT value,encrypted_credentials FROM system_settings WHERE `key`=? FOR UPDATE');
        $s->execute([$key]);

        return $s->fetch() ?: null;
    }

    private function version(PDO $db, string $key, ?array $row): string
    {
        $action = $key === 'notify.mail' ? 'notification_configure' : 'ticket_notification_configure';
        $s = $db->prepare("SELECT COALESCE(MAX(id),0) FROM admin_audit_logs WHERE target_type='notification' AND action=?");
        $s->execute([$action]);

        return Security::requestFingerprint(json_encode([$key, $row, $s->fetchColumn()], JSON_THROW_ON_ERROR));
    }

    private function checkVersion(PDO $db, string $key, ?array $row, array $input): void
    {
        if (! hash_equals($this->version($db, $key, $row), Request::text($input, 'expected_version', 64, 64))) {
            throw new ApiException('Notification configuration changed; reload and review again', 409, 409);
        }
    }

    private function privateConfig(?array $row): array
    {
        if (! $row) {
            return [];
        }$plain = Security::decrypt($row['encrypted_credentials'] ?? '');
        if ($plain === null) {
            throw new \RuntimeException('Mail configuration unavailable');
        }

        return json_decode($plain, true, 32, JSON_THROW_ON_ERROR);
    }

    /** Only the SMTP password is withheld; neither ciphertext nor a placeholder is a password input. */
    public function show(int $admin): array
    {
        return Database::transaction(function (PDO $db) use ($admin): array {
            self::authorize($db, $admin);
            $mail = $this->row($db, 'notify.mail');
            $ticket = $this->row($db, 'notify.ticket_mail');
            $value = $mail ? json_decode($mail['value'], true, 32, JSON_THROW_ON_ERROR) : ['enabled' => false, 'task_alerts' => false, 'due_reminders' => false, 'site_url' => '', 'subject_prefix' => '', 'admin_ids' => []];
            $private = $this->privateConfig($mail);
            $value = array_intersect_key($value, array_flip(['enabled', 'task_alerts', 'due_reminders', 'site_url', 'subject_prefix', 'admin_ids']));
            $value['config'] = array_replace(['host' => '', 'port' => 465, 'security' => 'smtps', 'username' => '', 'from' => '', 'from_name' => ''], array_intersect_key($private, array_flip(['host', 'port', 'security', 'username', 'from', 'from_name'])));
            $value['password_configured'] = is_string($private['password'] ?? null) && $private['password'] !== '';
            $value['version'] = $this->version($db, 'notify.mail', $mail);
            $subscription = $ticket ? json_decode($ticket['value'], true, 32, JSON_THROW_ON_ERROR) : ['enabled' => false, 'admin_ids' => [], 'activated_at' => null];
            $subscription = array_intersect_key($subscription, array_flip(['enabled', 'admin_ids', 'activated_at']));
            $subscription['version'] = $this->version($db, 'notify.ticket_mail', $ticket);
            $admins = $db->query("SELECT id,username,email FROM admins WHERE status='active' ORDER BY id")->fetchAll();

            return ['mail' => $value, 'tickets' => $subscription, 'recipients' => $admins];
        }, 3);
    }

    public function save(int $admin, array $input): void
    {
        if (array_diff(array_keys($input), ['expected_version', 'enabled', 'task_alerts', 'due_reminders', 'site_url', 'subject_prefix', 'admin_ids', 'config', 'password_action', 'password'])
            || ! in_array($input['password_action'] ?? null, ['keep', 'replace'], true)
            || ! is_array($input['config'] ?? null)
            || array_diff(array_keys($input['config']), ['host', 'port', 'security', 'username', 'from', 'from_name'])
            || ($input['password_action'] === 'keep' && array_key_exists('password', $input))
            || ($input['password_action'] === 'replace' && (! is_string($input['password'] ?? null) || $input['password'] === '' || strlen($input['password']) > 4096))) {
            throw new ApiException('Explicit password replacement or retention required');
        }
        Database::transaction(function (PDO $db) use ($admin, $input): void {
            self::authorize($db, $admin);
            $row = $this->row($db, 'notify.mail');
            $this->checkVersion($db, 'notify.mail', $row, $input);
            $config = $input['config'];
            $config['password'] = $input['password_action'] === 'replace' ? $input['password'] : ($this->privateConfig($row)['password'] ?? null);
            if (($config['username'] ?? '') !== '' && ($config['password'] ?? '') === '') {
                throw new ApiException('SMTP password required for authenticated mail');
            }
            $payload = array_intersect_key($input, array_flip(['enabled', 'task_alerts', 'due_reminders', 'site_url', 'subject_prefix', 'admin_ids']));
            $payload['config'] = $config;
            $this->configure($admin, $payload, $input['password_action']);
            // Never touch notify.ticket_mail: a normal save is not a new ticket subscription.
        }, 3);
    }

    public function saveTickets(int $admin, array $input): void
    {
        if (array_diff(array_keys($input), ['expected_version', 'enabled', 'admin_ids', 'confirm_new_period']) || ($input['confirm_new_period'] ?? null) !== true) {
            throw new ApiException('Explicit confirmation of a new ticket subscription period required');
        }
        Database::transaction(function (PDO $db) use ($admin, $input): void {
            self::authorize($db, $admin);
            $this->row($db, 'notify.mail');
            $row = $this->row($db, 'notify.ticket_mail');
            $this->checkVersion($db, 'notify.ticket_mail', $row, $input);
            $this->configureTickets($admin, array_intersect_key($input, array_flip(['enabled', 'admin_ids'])));
        }, 3);
    }

    public static function senderRevision(array $row): string
    {
        return Security::requestFingerprint(json_encode([$row['value'], $row['encrypted_credentials']], JSON_THROW_ON_ERROR));
    }

    public function load(): ?array
    {
        $s = Database::getInstance()->query("SELECT value,encrypted_credentials FROM system_settings WHERE `key`='notify.mail'");
        $row = $s->fetch();
        if (! $row) {
            return null;
        }
        $value = json_decode($row['value'], true, 32, JSON_THROW_ON_ERROR);
        if (($value['enabled'] ?? false) !== true) {
            return null;
        }
        $plain = Security::decrypt($row['encrypted_credentials'] ?? '');
        if ($plain === null) {
            throw new \RuntimeException('Mail configuration unavailable');
        }

        return $value + ['config' => json_decode($plain, true, 32, JSON_THROW_ON_ERROR), 'settings_revision' => self::senderRevision($row)];
    }
}
