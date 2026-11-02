<?php
/**
 * Key/value settings stored in the database (cached per request).
 */

namespace App\Core;

final class Settings
{
    private static $cache = null;

    private static function load()
    {
        if (self::$cache !== null) {
            return;
        }
        self::$cache = array();
        try {
            $rows = Database::fetchAll('SELECT skey, svalue FROM {settings}');
            foreach ($rows as $row) {
                self::$cache[$row['skey']] = $row['svalue'];
            }
        } catch (\Throwable $e) {
            self::$cache = array();
        }
    }

    public static function get($key, $default = null)
    {
        self::load();
        return array_key_exists($key, self::$cache) ? self::$cache[$key] : $default;
    }

    public static function bool($key, $default = false)
    {
        $value = self::get($key, null);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower((string) $value), array('1', 'true', 'yes', 'on'), true);
    }

    public static function int($key, $default = 0)
    {
        $value = self::get($key, null);
        return $value === null ? $default : (int) $value;
    }

    public static function set($key, $value)
    {
        self::load();
        $exists = array_key_exists($key, self::$cache);
        self::$cache[$key] = $value;
        if ($exists) {
            Database::update('settings', array('svalue' => $value, 'updated_at' => date('Y-m-d H:i:s')), 'skey = :skey', array('skey' => $key));
        } else {
            Database::insert('settings', array('skey' => $key, 'svalue' => $value, 'updated_at' => date('Y-m-d H:i:s')));
        }
        return true;
    }

    public static function all()
    {
        self::load();
        return self::$cache;
    }

    public static function forget($key)
    {
        self::load();
        unset(self::$cache[$key]);
        Database::delete('settings', 'skey = :skey', array('skey' => $key));
    }
}