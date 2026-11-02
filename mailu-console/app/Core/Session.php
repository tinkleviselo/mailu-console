<?php
/**
 * Hardened session bootstrap.
 */

namespace App\Core;

final class Session
{
    public static function start()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = Request::isSecure();
        $params = array(
            'lifetime' => (int) Config::get('session.lifetime', 120) * 60,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        );
        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params($params);
        } else {
            session_set_cookie_params($params['lifetime'], $params['path'], $params['domain'], $secure, true);
        }
        session_name((string) Config::get('session.name', 'mailu_console'));
        session_start();
    }

    public static function get($key, $default = null)
    {
        return array_key_exists($key, $_SESSION) ? $_SESSION[$key] : $default;
    }

    public static function set($key, $value)
    {
        $_SESSION[$key] = $value;
    }

    public static function forget($key)
    {
        unset($_SESSION[$key]);
    }

    public static function regenerate()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy()
    {
        $_SESSION = array();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}