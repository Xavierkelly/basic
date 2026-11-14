<?php
declare(strict_types=1);

final class Auth
{
    private static ?array $user = null;
    private static ?array $company = null;

    public const ROLES = ['owner' => 4, 'admin' => 3, 'manager' => 2, 'employee' => 1];

    public static function user(): ?array
    {
        if (self::$user === null && !empty($_SESSION['uid'])) {
            self::$user = Db::one('SELECT * FROM users WHERE id = ? AND active = 1', [$_SESSION['uid']]);
            if (!self::$user) {
                unset($_SESSION['uid']);
            }
        }
        return self::$user;
    }

    public static function company(): ?array
    {
        $u = self::user();
        if ($u && self::$company === null) {
            self::$company = Db::one('SELECT * FROM companies WHERE id = ?', [$u['company_id']]);
        }
        return self::$company;
    }

    public static function companyId(): int
    {
        return (int) self::user()['company_id'];
    }

    public static function attempt(string $email, string $password): bool
    {
        $email = strtolower(trim($email));
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
        $recent = (int) Db::value(
            'SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)',
            [$email]
        );
        if ($recent >= 8) {
            return false;
        }
        $u = Db::one('SELECT * FROM users WHERE email = ? AND active = 1', [$email]);
        if (!$u || !password_verify($password, $u['password_hash'])) {
            Db::insert('login_attempts', ['email' => $email, 'ip' => $ip]);
            return false;
        }
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            Db::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], ['id' => $u['id']]);
        }
        Db::exec('DELETE FROM login_attempts WHERE email = ?', [$email]);
        self::loginAs((int) $u['id']);
        return true;
    }

    public static function loginAs(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['uid'] = $userId;
        self::$user = null;
        self::$company = null;
        Db::exec('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?', [$userId]);
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        self::$user = null;
        self::$company = null;
    }

    public static function can(string $minRole): bool
    {
        $u = self::user();
        return $u && self::ROLES[$u['role']] >= self::ROLES[$minRole];
    }

    public static function require(string $minRole = 'employee'): array
    {
        $u = self::user();
        if (!$u) {
            if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
                json_out(['ok' => false, 'error' => 'Please sign in again.'], 401);
            }
            redirect('login');
        }
        if (!self::can($minRole)) {
            if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
                json_out(['ok' => false, 'error' => 'You do not have access to that.'], 403);
            }
            http_response_code(403);
            exit('You do not have access to this page.');
        }
        return $u;
    }

    /** The employee record linked to the signed-in user, if any. */
    public static function employee(): ?array
    {
        $u = self::user();
        if (!$u || !$u['employee_id']) {
            return null;
        }
        return Db::one('SELECT * FROM employees WHERE id = ? AND company_id = ?', [$u['employee_id'], $u['company_id']]);
    }
}
