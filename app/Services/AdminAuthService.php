<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\{ApiException, Database, Request, Security};
use Illuminate\Support\Facades\RateLimiter;
use PDO;

final class AdminAuthService
{
    public function login(array $input): array
    {
        $username = trim(Request::text($input, 'username', 1, 50));
        $password = Request::text($input, 'password', 1, 72);
        // Separate from client login; captcha is still enforced by the route middleware.
        $key = 'admin-login:'.Security::requestFingerprint(Security::getClientIp());
        if (RateLimiter::tooManyAttempts($key, 10)) { throw new ApiException('Too many login attempts; try again in one minute', 429, 429); }
        RateLimiter::hit($key, 60);
        $s = Database::getInstance()->prepare('SELECT id,password FROM admins WHERE username = ?');
        $s->execute([$username]); $snapshot = $s->fetch();
        $hash = $snapshot['password'] ?? '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        if (str_contains($password, "\0") || !Security::verifyPassword($password, $hash) || !$snapshot) {
            throw new ApiException('Invalid username or password', 401, 401);
        }
        $newHash = password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost'=>12]) ? Security::hashPassword($password) : null;
        return Database::transaction(static function (PDO $db) use ($snapshot, $newHash): array {
            $s = $db->prepare('SELECT id,username,email,role,status,password,two_factor_secret FROM admins WHERE id = ? FOR UPDATE');
            $s->execute([$snapshot['id']]); $admin = $s->fetch();
            if (!$admin || !hash_equals($snapshot['password'], $admin['password'])) { throw new ApiException('Credentials changed; log in again', 409, 409); }
            if ($admin['status'] !== 'active') { throw new ApiException('Admin account disabled', 403, 403); }
            // Never silently bypass an existing second factor. TOTP enrollment/verification is outside this batch.
            if ($admin['two_factor_secret'] !== null && $admin['two_factor_secret'] !== '') { throw new ApiException('Second-factor login is not available in this release', 403, 403); }
            if ($newHash !== null) { $db->prepare('UPDATE admins SET password = ? WHERE id = ?')->execute([$newHash,$admin['id']]); }
            $db->prepare('UPDATE admins SET last_login_at = UTC_TIMESTAMP(),last_login_ip = ? WHERE id = ?')->execute([Security::getClientIp(),$admin['id']]);
            unset($admin['password'], $admin['two_factor_secret']);
            return ['token'=>Security::generateToken(['uid'=>(int)$admin['id'],'sub_type'=>'admin','av'=>self::sessionVersion($db,(int)$admin['id'],true)]), 'admin'=>$admin];
        });
    }

    /** Immutable account-security audits are the revocation source; legacy provisioning stays version zero. */
    public static function sessionVersion(PDO $db,int $id,bool $lock=false): int
    {
        $s=$db->prepare("SELECT id FROM admin_audit_logs WHERE target_type='admin' AND target_id=? AND action IN ('admin_account_create','admin_account_update','admin_password_change','admin_password_reset') ORDER BY id DESC LIMIT 1".($lock?' FOR UPDATE':''));
        $s->execute([$id]);return (int)($s->fetchColumn()?:0);
    }

    /** Local provisioning shares validation, account locks and mandatory audit with the web service. */
    public function create(array $input): int
    {
        return (new AdminAccountService())->createLocal($input);
    }
}
