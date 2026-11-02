<?php
/**
 * Mailu Console - PSR-4 style autoloader (no composer dependency).
 */

namespace App\Core;

final class Autoloader
{
    public static function register()
    {
        spl_autoload_register(array(__CLASS__, 'load'));
    }

    public static function load($class)
    {
        $prefix = 'App\\';
        if (strpos($class, $prefix) !== 0) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $path = APP_PATH . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
}