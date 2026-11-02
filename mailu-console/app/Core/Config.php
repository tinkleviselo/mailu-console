<?php
/**
 * Configuration container. Reads config/config.php (a plain PHP array).
 */

namespace App\Core;

final class Config
{
    private static $items = array();

    public static function load($file)
    {
        if (is_file($file)) {
            $data = require $file;
            if (is_array($data)) {
                self::$items = $data;
            }
        }
        return self::$items;
    }

    public static function all()
    {
        return self::$items;
    }

    public static function get($key, $default = null)
    {
        $node = self::$items;
        foreach (explode('.', (string) $key) as $part) {
            if (is_array($node) && array_key_exists($part, $node)) {
                $node = $node[$part];
            } else {
                return $default;
            }
        }
        return $node;
    }

    public static function set($key, $value)
    {
        $parts = explode('.', (string) $key);
        $node = &self::$items;
        foreach ($parts as $part) {
            if (!isset($node[$part]) || !is_array($node[$part])) {
                $node[$part] = array();
            }
            $node = &$node[$part];
        }
        $node = $value;
        return true;
    }

    public static function has($key)
    {
        return self::get($key, '__missing__') !== '__missing__';
    }

    /** True when the console has been installed (config + app key present). */
    public static function isInstalled()
    {
        return is_file(CONFIG_PATH) && (string) self::get('app.key', '') !== '';
    }

    public static function version()
    {
        return (string) self::get('app.version', '1.0.0');
    }

    public static function write($file, array $items = null)
    {
        $data = $items === null ? self::$items : $items;
        $php = "<?php\n// Mailu Console configuration - generated " . date('c') . "\nreturn " . var_export($data, true) . ";\n";
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0750, true);
        }
        return file_put_contents($file, $php, LOCK_EX) !== false;
    }
}