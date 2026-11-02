<?php
/**
 * Mailu Console bootstrap: paths, autoloading, config, session, routing.
 * Pure PHP (>= 7.4), no composer, no external CDN.
 */

define('MC_VERSION', '1.0.0');
define('MC_SCHEMA_VERSION', '3');
define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
define('VIEW_PATH', BASE_PATH . '/views');
define('STORAGE_PATH', BASE_PATH . '/storage');
define('CONFIG_PATH', BASE_PATH . '/config/config.php');

require APP_PATH . '/Core/Autoloader.php';
App\Core\Autoloader::register();
require APP_PATH . '/Core/Helpers.php';

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;

Config::load(CONFIG_PATH);

date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Shanghai'));

if (Config::get('app.debug', false)) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

if (!is_dir(STORAGE_PATH)) {
    @mkdir(STORAGE_PATH, 0750, true);
}

// --- schema bootstrap (runs once per schema revision) -----------------------
if (Config::isInstalled()) {
    $lock = STORAGE_PATH . '/schema.lock';
    $current = is_file($lock) ? trim((string) file_get_contents($lock)) : '';
    if ($current !== MC_SCHEMA_VERSION) {
        try {
            Database::migrate();
            @file_put_contents($lock, MC_SCHEMA_VERSION);
        } catch (Throwable $e) {
            Logger::error('migration failed', array('error' => $e->getMessage()));
        }
    }
}

// --- error handling ---------------------------------------------------------
set_exception_handler(function ($e) {
    Logger::error('unhandled exception', array('error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()));
    Response::abort(500, Config::get('app.debug', false) ? $e->getMessage() : '服务器内部错误，请查看 storage/logs 中的日志。');
});

Session::start();

View::share('appName', (string) Config::get('app.name', 'Mailu 邮件中台'));
View::share('appVersion', MC_VERSION);
View::share('adminUser', Config::isInstalled() ? Auth::user() : null);
View::share('flashMessages', Flash::pull());
View::share('currentPath', Request::path());
View::share('isApiRequest', Request::wantsJson());

if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-XSS-Protection: 1; mode=block');
}

$router = new Router();
$GLOBALS['__router'] = $router;

require APP_PATH . '/routes.php';

return $router;