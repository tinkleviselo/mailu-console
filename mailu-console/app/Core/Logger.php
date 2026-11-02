<?php
/**
 * Very small file logger: storage/logs/app-YYYY-MM-DD.log
 */

namespace App\Core;

final class Logger
{
    private static $channel = 'app';

    public static function channel($name)
    {
        self::$channel = preg_replace('/[^a-z0-9_\-]/i', '', (string) $name);
        return new self();
    }

    public static function log($level, $message, array $context = array())
    {
        $dir = STORAGE_PATH . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $line = sprintf(
            "[%s] %s.%s: %s%s\n",
            date('Y-m-d H:i:s'),
            self::$channel,
            strtoupper($level),
            $message,
            $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );
        @file_put_contents($dir . '/' . self::$channel . '-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    public static function info($message, array $context = array())
    {
        self::log('info', $message, $context);
    }

    public static function warn($message, array $context = array())
    {
        self::log('warn', $message, $context);
    }

    public static function error($message, array $context = array())
    {
        self::log('error', $message, $context);
    }

    public static function debug($message, array $context = array())
    {
        if (Config::get('app.debug', false)) {
            self::log('debug', $message, $context);
        }
    }

    /** Last N lines of today's (or the newest) log file. */
    public static function tail($lines = 200)
    {
        $files = glob(STORAGE_PATH . '/logs/*.log');
        if (!$files) {
            return array();
        }
        rsort($files);
        $content = (string) @file_get_contents($files[0]);
        $rows = preg_split('/\r?\n/', trim($content));
        return array_slice($rows, -1 * abs((int) $lines));
    }
}