<?php
/**
 * Console administrator authentication with simple brute-force throttling.
 */

namespace App\Core;

final class Auth
{
    private static $user = null;
    private static $loaded = false;

    public static function check()
    {
        return self::user() !== null;
    }

    public static function user()
    {
        if (self::$loaded) {
            return self::$user;
        }
        self::$loaded = true;
        $id = Session::get('admin_id');
        if (!$id) {
            return null;
        }
        try {
            self::$user = Database::fetch('SELECT * FROM {admins} WHERE id = ?', array((int) $id));
        } catch (\Throwable $e) {
            self::$user = null;
        }
        return self::$user;
    }

    public static function id()
    {
        $user = self::user();
        return $user ? (int) $user['id'] : null;
    }

    public static function name()
    {
        $user = self::user();
        return $user ? (string) $user['username'] : 'system';
    }

    public static function requireLogin()
    {
        if (self::check()) {
            return;
        }
        if (Request::wantsJson()) {
            Response::json(array('ok' => false, 'error' => '未登录或会话已过期'), 401);
        }
        Session::set('__intended', Request::path());
        Response::redirect('/login');
    }

    public static function attempt($username, $password)
    {
        $username = trim((string) $username);
        if ($username === '' || $password === '') {
            return false;
        }
        if (self::isLocked($username)) {
            Logger::warn('login locked', array('username' => $username, 'ip' => Request::ip()));
            return false;
        }
        $admin = Database::fetch('SELECT * FROM {admins} WHERE username = ?', array($username));
        $ok = false;
        if ($admin && password_verify((string) $password, (string) $admin['password_hash'])) {
            $ok = true;
            self::login($admin);
        }
        self::recordAttempt($username, $ok);
        if (!$ok) {
            Logger::warn('login failed', array('username' => $username, 'ip' => Request::ip()));
        }
        return $ok;
    }

    public static function login(array $admin)
    {
        Session::regenerate();
        Session::set('admin_id', (int) $admin['id']);
        Session::set('admin_name', (string) $admin['username']);
        self::$user = $admin;
        self::$loaded = true;
        Database::update('admins', array('last_login_at' => date('Y-m-d H:i:s'), 'last_login_ip' => Request::ip()), 'id = :id', array('id' => (int) $admin['id']));
        Logger::info('login success', array('username' => $admin['username'], 'ip' => Request::ip()));
    }

    public static function logout()
    {
        Logger::info('logout', array('admin' => self::name()));
        Session::destroy();
        self::$user = null;
        self::$loaded = true;
    }

    public static function createAdmin($username, $password, $email = '', $displayName = '')
    {
        return Database::insert('admins', array(
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'email' => $email,
            'display_name' => $displayName !== '' ? $displayName : $username,
            'is_super' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ));
    }

    public static function updatePassword($adminId, $password)
    {
        return Database::update('admins', array('password_hash' => password_hash($password, PASSWORD_DEFAULT)), 'id = :id', array('id' => (int) $adminId));
    }

    public static function adminCount()
    {
        try {
            return (int) Database::value('SELECT COUNT(*) FROM {admins}', array(), 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public static function isLocked($username, $maxAttempts = 8, $windowMinutes = 15)
    {
        $since = date('Y-m-d H:i:s', time() - $windowMinutes * 60);
        $fails = (int) Database::value(
            'SELECT COUNT(*) FROM {login_attempts} WHERE username = ? AND success = 0 AND created_at >= ?',
            array($username, $since),
            0
        );
        return $fails >= $maxAttempts;
    }

    private static function recordAttempt($username, $success)
    {
        try {
            Database::insert('login_attempts', array(
                'username' => $username,
                'ip' => Request::ip(),
                'success' => $success ? 1 : 0,
                'created_at' => date('Y-m-d H:i:s'),
            ));
            if (random_int(1, 20) === 1) {
                Database::delete('login_attempts', 'created_at < :t', array('t' => date('Y-m-d H:i:s', time() - 7 * 86400)));
            }
        } catch (\Throwable $e) {
            // never block login on audit failure
        }
    }
}