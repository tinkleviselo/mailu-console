<?php
/**
 * Global view / formatting helpers (kept as plain functions: they are used
 * from every view file).
 */

use App\Core\Config;
use App\Core\Request;
use App\Core\Session;

if (!function_exists('e')) {
    /** HTML escape. */
    function e($value)
    {
        if (is_array($value) || is_object($value)) {
            return htmlspecialchars(json_encode($value, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
        }
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('router')) {
    function router()
    {
        return isset($GLOBALS['__router']) ? $GLOBALS['__router'] : null;
    }
}

if (!function_exists('url')) {
    function url($path = '/')
    {
        if ($path === '' || $path === null) {
            return Request::basePath() . '/';
        }
        if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) {
            return $path;
        }
        return Request::basePath() . '/' . ltrim($path, '/');
    }
}

if (!function_exists('route')) {
    function route($name, array $params = array())
    {
        $r = router();
        return $r ? url($r->path($name, $params)) : url('/');
    }
}

if (!function_exists('asset')) {
    function asset($path)
    {
        return url('assets/' . ltrim($path, '/')) . '?v=' . rawurlencode((string) Config::version());
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token()
    {
        return \App\Core\Csrf::token();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field()
    {
        return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('partial')) {
    function partial($view, array $data = array())
    {
        $v = new \App\Core\View();
        echo $v->render($view, $data, null);
    }
}

if (!function_exists('old')) {
    function old($key, $default = '')
    {
        $all = Session::get('__old', array());
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }
}

if (!function_exists('errors')) {
    function errors($key = null)
    {
        $all = Session::get('__errors', array());
        if ($key === null) {
            return $all;
        }
        return isset($all[$key]) ? $all[$key] : null;
    }
}

if (!function_exists('str_limit')) {
    function str_limit($value, $limit = 60, $end = '...')
    {
        $value = (string) $value;
        if (function_exists('mb_strlen')) {
            if (mb_strlen($value, 'UTF-8') <= $limit) {
                return $value;
            }
            return mb_substr($value, 0, $limit, 'UTF-8') . $end;
        }
        return strlen($value) <= $limit ? $value : substr($value, 0, $limit) . $end;
    }
}

if (!function_exists('format_bytes')) {
    function format_bytes($bytes, $precision = 2)
    {
        $bytes = (float) $bytes;
        if ($bytes <= 0) {
            return '不限';
        }
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}

if (!function_exists('bytes_to_mb')) {
    function bytes_to_mb($bytes)
    {
        return round(((float) $bytes) / 1048576, 2);
    }
}

if (!function_exists('mb_to_bytes')) {
    function mb_to_bytes($mb)
    {
        return (int) round(((float) $mb) * 1048576);
    }
}

if (!function_exists('human_time')) {
    function human_time($value)
    {
        if (empty($value)) {
            return '—';
        }
        $ts = is_numeric($value) ? (int) $value : strtotime((string) $value);
        if (!$ts) {
            return (string) $value;
        }
        $diff = time() - $ts;
        if ($diff >= 0 && $diff < 60) {
            return '刚刚';
        }
        if ($diff >= 60 && $diff < 3600) {
            return floor($diff / 60) . ' 分钟前';
        }
        if ($diff >= 3600 && $diff < 86400) {
            return floor($diff / 3600) . ' 小时前';
        }
        if ($diff >= 86400 && $diff < 2592000) {
            return floor($diff / 86400) . ' 天前';
        }
        return date('Y-m-d H:i', $ts);
    }
}

if (!function_exists('random_password')) {
    function random_password($length = 16)
    {
        $lower = 'abcdefghijkmnopqrstuvwxyz';
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $digits = '23456789';
        $symbols = '!@#$%^&*-_=+';
        $all = $lower . $upper . $digits . $symbols;
        $length = max(8, (int) $length);
        $password = $lower[random_int(0, strlen($lower) - 1)]
            . $upper[random_int(0, strlen($upper) - 1)]
            . $digits[random_int(0, strlen($digits) - 1)]
            . $symbols[random_int(0, strlen($symbols) - 1)];
        while (strlen($password) < $length) {
            $password .= $all[random_int(0, strlen($all) - 1)];
        }
        $chars = str_split($password);
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            $tmp = $chars[$i];
            $chars[$i] = $chars[$j];
            $chars[$j] = $tmp;
        }
        return implode('', $chars);
    }
}

if (!function_exists('random_token')) {
    function random_token($bytes = 24)
    {
        return bin2hex(random_bytes($bytes));
    }
}

if (!function_exists('json_field')) {
    function json_field($value, $default = array())
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value === null || $value === '') {
            return $default;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : $default;
    }
}

if (!function_exists('badge')) {
    function badge($ok, $yes = '正常', $no = '异常')
    {
        return '<span class="badge ' . ($ok ? 'badge-ok' : 'badge-bad') . '">' . e($ok ? $yes : $no) . '</span>';
    }
}

if (!function_exists('qs')) {
    /** Current query string with overrides - used by pagination / filter links. */
    function qs(array $overrides = array())
    {
        $query = array_merge($_GET, $overrides);
        foreach ($query as $key => $value) {
            if ($value === null || $value === '') {
                unset($query[$key]);
            }
        }
        $string = http_build_query($query);
        return $string === '' ? '' : '?' . $string;
    }
}

if (!function_exists('status_badge')) {
    function status_badge($value)
    {
        $map = array(
            'ok' => array('badge-ok', '成功'),
            'success' => array('badge-ok', '成功'),
            'failed' => array('badge-bad', '失败'),
            'error' => array('badge-bad', '错误'),
            'locked' => array('badge-warn', '锁定'),
            'partial' => array('badge-warn', '部分成功'),
            'running' => array('badge-muted', '进行中'),
            'warning' => array('badge-warn', '提示'),
        );
        $key = strtolower((string) $value);
        if (isset($map[$key])) {
            return '<span class="badge ' . $map[$key][0] . '">' . e($map[$key][1]) . '</span>';
        }
        return '<span class="badge badge-muted">' . e($value) . '</span>';
    }
}
