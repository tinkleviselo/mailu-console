<?php
/**
 * Request helpers (all static - the console is a plain PHP request/response app).
 */

namespace App\Core;

final class Request
{
    public static function method()
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
        if ($method === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper((string) $_POST['_method']);
            if (in_array($override, array('PUT', 'PATCH', 'DELETE'), true)) {
                return $override;
            }
        }
        return $method;
    }

    public static function path()
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
        $uri = explode('?', $uri, 2)[0];
        $base = self::basePath();
        if ($base !== '' && strpos($uri, $base) === 0) {
            $uri = substr($uri, strlen($base));
        }
        $uri = '/' . ltrim(rawurldecode($uri), '/');
        if ($uri !== '/' ) {
            $uri = rtrim($uri, '/');
        }
        return $uri === '' ? '/' : $uri;
    }

    public static function basePath()
    {
        $configured = (string) Config::get('app.base_path', '');
        if ($configured !== '') {
            return '/' . trim($configured, '/');
        }
        $script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
        $dir = str_replace('\\', '/', dirname($script));
        return ($dir === '/' || $dir === '.') ? '' : rtrim($dir, '/');
    }

    public static function input($key, $default = null)
    {
        $all = self::all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function all()
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $data = array_merge($_GET, $_POST);
        $raw = file_get_contents('php://input');
        $type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
        if ($raw !== '' && (stripos($type, 'application/json') !== false)) {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $data = array_merge($data, $json);
            }
        }
        $cache = $data;
        return $cache;
    }

    public static function has($key)
    {
        return array_key_exists($key, self::all());
    }

    public static function str($key, $default = '')
    {
        $value = self::input($key, $default);
        if (is_array($value)) {
            return $default;
        }
        return trim((string) $value);
    }

    public static function int($key, $default = 0)
    {
        $value = self::input($key, $default);
        if ($value === null || $value === '') {
            return $default;
        }
        return (int) $value;
    }

    public static function bool($key, $default = false)
    {
        if (!self::has($key)) {
            return $default;
        }
        $value = self::input($key);
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string) $value), array('1', 'true', 'on', 'yes'), true);
    }

    public static function query($key = null, $default = null)
    {
        if ($key === null) {
            return $_GET;
        }
        return array_key_exists($key, $_GET) ? $_GET[$key] : $default;
    }

    public static function ip()
    {
        foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR') as $key) {
            if (!empty($_SERVER[$key])) {
                $parts = explode(',', (string) $_SERVER[$key]);
                return trim($parts[0]);
            }
        }
        return '0.0.0.0';
    }

    public static function userAgent()
    {
        return isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : '';
    }

    public static function header($name, $default = null)
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key])) {
            return $_SERVER[$key];
        }
        if (strtolower($name) === 'content-type' && isset($_SERVER['CONTENT_TYPE'])) {
            return $_SERVER['CONTENT_TYPE'];
        }
        if (strtolower($name) === 'authorization' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            return $_SERVER['HTTP_AUTHORIZATION'];
        }
        return $default;
    }

    public static function bearerToken()
    {
        $header = (string) self::header('Authorization', '');
        if (stripos($header, 'bearer ') === 0) {
            return trim(substr($header, 7));
        }
        return (string) self::input('api_token', '');
    }

    public static function isPost()
    {
        return self::method() === 'POST';
    }

    public static function isSecure()
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }
        return (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
    }

    public static function wantsJson()
    {
        $accept = (string) self::header('Accept', '');
        if (stripos($accept, 'application/json') !== false) {
            return true;
        }
        $uri = self::path();
        return strpos($uri, '/api/') === 0;
    }

    public static function file($key)
    {
        return isset($_FILES[$key]) && is_array($_FILES[$key]) ? $_FILES[$key] : null;
    }

    public static function uploadedText($key)
    {
        $file = self::file($key);
        if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            return null;
        }
        return (string) file_get_contents($file['tmp_name']);
    }
}