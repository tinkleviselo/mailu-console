<?php
/**
 * 健康检查端点（用于 Docker healthcheck / 负载均衡探活）。
 * 只暴露最小信息，不泄露配置。
 */

$root = dirname(__DIR__);
$configFile = $root . '/config/config.php';
$installed = is_file($configFile);
$dbOk = false;
$dbDriver = '';

if ($installed) {
    define('BASE_PATH', $root);
    define('APP_PATH', BASE_PATH . '/app');
    define('STORAGE_PATH', BASE_PATH . '/storage');
    define('CONFIG_PATH', $configFile);
    define('VIEW_PATH', BASE_PATH . '/views');
    require APP_PATH . '/Core/Autoloader.php';
    App\Core\Autoloader::register();
    require APP_PATH . '/Core/Helpers.php';
    App\Core\Config::load(CONFIG_PATH);
    $dbDriver = (string) App\Core\Config::get('db.driver', 'sqlite');
    try {
        App\Core\Database::pdo()->query('SELECT 1');
        $dbOk = true;
    } catch (Throwable $e) {
        $dbOk = false;
    }
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
http_response_code($installed && $dbOk ? 200 : 503);
echo json_encode(array(
    'ok' => $installed && $dbOk,
    'installed' => $installed,
    'db' => $dbOk ? 'up' : 'down',
    'driver' => $dbDriver,
    'time' => date('c'),
), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);