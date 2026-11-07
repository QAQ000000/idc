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
            return ['token'=>Security::generateToken(['uid'=>(int)$admin['id'],'sub_type'=>'admin']), 'admin'=>$admin];
        });
    }

    /** Local operator provisioning only; never exposed as registration or an account reset endpoint. */
    public function create(array $input): int
    {
        $username = Request::text($input, 'username', 1, 50);
        $email = strtolower(trim(Request::text($input, 'email', 3, 100)));
        $role = Request::text($input, 'role', 1, 20);
        if ($username !== trim($username) || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || !in_array($role, ['super_admin','finance','support','operator'], true)) { throw new ApiException('Invalid administrator'); }
        $hash = Security::hashPassword(Request::text($input, 'password', 12, 72));
        return Database::transaction(static function (PDO $db) use ($username,$email,$role,$hash): int {
            $db->prepare('INSERT INTO admins(username,email,password,role) VALUES(?,?,?,?)')->execute([$username,$email,$hash,$role]);
            $id = (int)$db->lastInsertId();
            $db->prepare("INSERT INTO admin_audit_logs(admin_id,action,target_type,target_id,context,ip) VALUES(?,'admin_provision','admin',?,?,?)")
                ->execute([$id,$id,json_encode(['source'=>'local_cli']),Security::getClientIp()]);
            return $id;
        });
    }
}
