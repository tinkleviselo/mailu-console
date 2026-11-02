<?php
/**
 * 中台命令行安装器（用于 Docker 首次启动 / CI / 无人值守上线）
 *
 * 用法:
 *   php app/cli/install.php --admin-user=admin --admin-pass=Secret123
 *   php app/cli/install.php --db=mysql --db-host=db --db-name=mailu_console \
 *        --db-user=mailu --db-pass=*** --wait-db=60 --json
 *
 * 也可全部通过环境变量提供：MC_APP_NAME MC_APP_KEY MC_BASE_PATH MC_TIMEZONE
 * MC_DB_DRIVER MC_DB_DATABASE MC_DB_HOST MC_DB_PORT MC_DB_NAME MC_DB_USER MC_DB_PASS
 * MC_ADMIN_USER MC_ADMIN_PASS MC_ADMIN_EMAIL MC_MAILU_VERSION MC_FORCE
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "该脚本只能在命令行下运行\n");
    exit(1);
}

define('MC_VERSION', '1.0.0');
define('MC_SCHEMA_VERSION', '3');
define('BASE_PATH', dirname(__DIR__, 2));
define('APP_PATH', BASE_PATH . '/app');
define('VIEW_PATH', BASE_PATH . '/views');
define('STORAGE_PATH', BASE_PATH . '/storage');
define('CONFIG_PATH', BASE_PATH . '/config/config.php');

require APP_PATH . '/Core/Autoloader.php';
App\Core\Autoloader::register();
require APP_PATH . '/Core/Helpers.php';

use App\Core\Auth;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Settings;

function mc_cli_option($name, $default = null)
{
    global $MC_OPTIONS;
    if (array_key_exists($name, $MC_OPTIONS)) {
        return $MC_OPTIONS[$name];
    }
    $env = 'MC_' . strtoupper(str_replace('-', '_', $name));
    $value = getenv($env);
    return ($value === false || $value === '') ? $default : $value;
}

$MC_OPTIONS = array();
$argvCopy = $argv;
array_shift($argvCopy);
foreach ($argvCopy as $arg) {
    if (strpos($arg, '--') !== 0) {
        continue;
    }
    $arg = substr($arg, 2);
    if (strpos($arg, '=') !== false) {
        list($key, $value) = explode('=', $arg, 2);
    } else {
        $key = $arg;
        $value = '1';
    }
    $MC_OPTIONS[$key] = $value;
}
$jsonOutput = isset($MC_OPTIONS['json']) || mc_cli_option('json', '0') === '1';

function out($message)
{
    fwrite(STDOUT, $message . "\n");
}

function fail($message)
{
    fwrite(STDERR, "[错误] " . $message . "\n");
    exit(1);
}

// --- 已安装则直接退出（幂等） ---
Config::load(CONFIG_PATH);
$force = mc_cli_option('force', '0') === '1';
if (Config::isInstalled() && !$force) {
    out('中台已安装，跳过初始化（如需重建请加 --force）');
    if ($jsonOutput) {
        echo json_encode(array('ok' => true, 'already_installed' => true), JSON_UNESCAPED_UNICODE) . "\n";
    }
    exit(0);
}

$adminUser = (string) mc_cli_option('admin-user', 'admin');
$adminPass = (string) mc_cli_option('admin-pass', '');
if ($adminPass === '') {
    $adminPass = bin2hex(random_bytes(9));
    out('未提供管理员密码，已随机生成: ' . $adminPass);
}
if (strlen($adminPass) < 10) {
    fail('管理员密码至少 10 位');
}
$adminEmail = (string) mc_cli_option('admin-email', '');

$driver = strtolower((string) mc_cli_option('db', mc_cli_option('db-driver', 'sqlite'))) === 'mysql' ? 'mysql' : 'sqlite';
$dbName = (string) mc_cli_option('db-name', 'mailu_console');
$dbDatabase = (string) mc_cli_option('db-database', $driver === 'mysql' ? $dbName : 'mailu.sqlite');
$dbHost = (string) mc_cli_option('db-host', '127.0.0.1');
$dbPort = (int) mc_cli_option('db-port', 3306);
$dbUser = (string) mc_cli_option('db-user', '');
$dbPass = (string) mc_cli_option('db-pass', '');
$waitDb = (int) mc_cli_option('wait-db', 0);

$config = array(
    'app' => array(
        'name' => (string) mc_cli_option('app-name', 'Mailu 邮件中台'),
        'version' => MC_VERSION,
        'key' => (string) mc_cli_option('app-key', Crypto::randomKey(32)),
        'debug' => false,
        'base_path' => trim((string) mc_cli_option('base-path', ''), '/'),
        'timezone' => (string) mc_cli_option('timezone', 'Asia/Shanghai'),
        'url' => (string) mc_cli_option('url', ''),
    ),
    'db' => array(
        'driver' => $driver,
        'database' => $dbDatabase,
        'host' => $dbHost,
        'port' => $dbPort,
        'name' => $dbName,
        'username' => $dbUser,
        'password' => $dbPass,
        'prefix' => 'mu_',
    ),
    'session' => array('name' => 'mailu_console_sid', 'lifetime' => 240),
    'mailu' => array(
        'api_path' => '/api/v1',
        'timeout' => 20,
        'default_quota_mb' => (int) mc_cli_option('default-quota-mb', 2048),
        'default_password_length' => 16,
        'sync_user_limit' => 2000,
    ),
    'deploy' => array(
        'mailu_version' => (string) mc_cli_option('mailu-version', '2024.06.61'),
        'mirror_prefix' => (string) mc_cli_option('mirror-prefix', ''),
        'allow_remote_deploy' => false,
        'ssh_binary' => 'ssh',
        'scp_binary' => 'scp',
    ),
    'security' => array('allow_api' => true, 'password_min_length' => 10),
);

foreach (array('storage', 'storage/logs', 'storage/cache', 'storage/deploy', 'config') as $dir) {
    $path = BASE_PATH . '/' . $dir;
    if (!is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) {
        fail('无法创建目录: ' . $path);
    }
}

if (!Config::write(CONFIG_PATH, $config)) {
    fail('无法写入 config/config.php，请检查目录权限');
}
@chmod(CONFIG_PATH, 0640);
Config::load(CONFIG_PATH);
out('已写入配置文件: ' . CONFIG_PATH);

// --- 数据库连接（可选等待，便于容器编排下等 DB 就绪） ---
$attempt = 0;
while (true) {
    $attempt++;
    try {
        Database::pdo();
        break;
    } catch (Throwable $e) {
        if ($waitDb > 0 && $attempt * 3 <= $waitDb) {
            out('等待数据库就绪（' . ($attempt * 3) . 's/' . $waitDb . 's）: ' . $e->getMessage());
            sleep(3);
            continue;
        }
        fail('数据库连接失败: ' . $e->getMessage());
    }
}
Database::migrate();
@file_put_contents(STORAGE_PATH . '/schema.lock', MC_SCHEMA_VERSION);
out('数据库已初始化（' . $driver . ': ' . $dbDatabase . '）');

$existingAdmin = (int) Database::value('SELECT id FROM {admins} WHERE username = ?', array($adminUser), 0);
if (Auth::adminCount() === 0) {
    Auth::createAdmin($adminUser, $adminPass, $adminEmail);
    out('已创建管理员: ' . $adminUser);
} elseif ($existingAdmin > 0) {
    Auth::updatePassword($existingAdmin, $adminPass);
    out('管理员已存在，密码已按参数更新: ' . $adminUser);
} else {
    Auth::createAdmin($adminUser, $adminPass, $adminEmail);
    out('已存在其他管理员，追加创建: ' . $adminUser);
}

$token = Settings::get('api.bearer_token', '');
if (!$token) {
    $token = bin2hex(random_bytes(24));
    Settings::set('api.bearer_token', $token);
    Settings::set('api.bearer_hash', hash('sha256', $token));
}
Settings::set('app.installed_at', date('Y-m-d H:i:s'));
Logger::info('console installed by cli', array('driver' => $driver, 'admin' => $adminUser));

out('中台 API 令牌: ' . $token);
out('初始化完成，请登录 ' . (mc_cli_option('url', '') !== '' ? mc_cli_option('url', '') : 'http://<host>/') . ' 使用。');
if ($jsonOutput) {
    echo json_encode(array(
        'ok' => true,
        'admin' => $adminUser,
        'api_token' => $token,
        'db' => $driver,
        'config' => CONFIG_PATH,
    ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}
exit(0);