<?php
/**
 * Symmetric encryption for credentials stored by the console (mail server API
 * tokens / mailbox passwords). AES-256-GCM when openssl is available.
 */

namespace App\Core;

final class Crypto
{
    private static $key = null;

    public static function key()
    {
        if (self::$key !== null) {
            return self::$key;
        }
        $configured = (string) Config::get('app.key', '');
        if ($configured !== '') {
            self::$key = hash('sha256', $configured, true);
            return self::$key;
        }
        $file = STORAGE_PATH . '/app.key';
        if (is_file($file)) {
            $raw = trim((string) file_get_contents($file));
            if ($raw !== '') {
                self::$key = hash('sha256', $raw, true);
                return self::$key;
            }
        }
        $raw = bin2hex(random_bytes(32));
        if (!is_dir(STORAGE_PATH)) {
            @mkdir(STORAGE_PATH, 0750, true);
        }
        @file_put_contents($file, $raw, LOCK_EX);
        @chmod($file, 0600);
        self::$key = hash('sha256', $raw, true);
        return self::$key;
    }

    public static function randomKey($bytes = 32)
    {
        return 'base64:' . base64_encode(random_bytes($bytes));
    }

    public static function encrypt($plain)
    {
        if ($plain === null || $plain === '') {
            return '';
        }
        $key = self::key();
        if (function_exists('openssl_encrypt')) {
            $iv = random_bytes(12);
            $tag = '';
            $cipher = openssl_encrypt((string) $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
            if ($cipher !== false) {
                return 'gcm:' . base64_encode($iv . $tag . $cipher);
            }
        }
        // Fallback: XOR stream keyed by HMAC (kept only so the console still runs
        // on PHP builds without openssl; prefer enabling openssl in production).
        $stream = hash_hmac('sha512', 'fallback', $key, true);
        $out = '';
        $len = strlen((string) $plain);
        for ($i = 0; $i < $len; $i++) {
            $out .= chr(ord($plain[$i]) ^ ord($stream[$i % strlen($stream)]));
        }
        return 'xor:' . base64_encode($out);
    }

    public static function decrypt($payload)
    {
        if ($payload === null || $payload === '') {
            return '';
        }
        $key = self::key();
        if (strpos($payload, 'gcm:') === 0) {
            $raw = base64_decode(substr($payload, 4));
            if ($raw === false || strlen($raw) < 29) {
                return '';
            }
            $iv = substr($raw, 0, 12);
            $tag = substr($raw, 12, 16);
            $cipher = substr($raw, 28);
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            return $plain === false ? '' : $plain;
        }
        if (strpos($payload, 'xor:') === 0) {
            $raw = base64_decode(substr($payload, 4));
            if ($raw === false) {
                return '';
            }
            $stream = hash_hmac('sha512', 'fallback', $key, true);
            $out = '';
            for ($i = 0; $i < strlen($raw); $i++) {
                $out .= chr(ord($raw[$i]) ^ ord($stream[$i % strlen($stream)]));
            }
            return $out;
        }
        return '';
    }

    /** Show only the last characters of a secret, for the UI. */
    public static function mask($secret)
    {
        $secret = (string) $secret;
        $len = strlen($secret);
        if ($len === 0) {
            return '(未设置)';
        }
        if ($len <= 8) {
            return str_repeat('*', $len);
        }
        return str_repeat('*', 6) . substr($secret, -4);
    }
}