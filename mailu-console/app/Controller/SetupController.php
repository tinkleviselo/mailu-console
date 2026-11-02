<?php
/**
 * First run wizard: requirements check, database selection, admin account.
 */

namespace App\Controller;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Validator;

final class SetupController extends Controller
{
    public function requirements()
    {
        $this->json(array('ok' => true, 'checks' => $this->checks()));
    }

    private function checks()
    {
        $checks = array();
        $checks[] = array('label' => 'PHP 版本 >= 7.4', 'ok' => version_compare(PHP_VERSION, '7.4.0', '>='), 'value' => PHP_VERSION);
        $checks[] = array('label' => 'PDO 扩展', 'ok' => extension_loaded('pdo'), 'value' => extension_loaded('pdo') ? '已启用' : '缺失');
        $checks[] = array('label' => 'SQLite 驱动 (或 MySQL)', 'ok' => extension_loaded('pdo_sqlite') || extension_loaded('pdo_mysql'), 'value' => implode(',', array_filter(array(extension_loaded('pdo_sqlite') ? 'pdo_sqlite' : '', extension_loaded('pdo_mysql') ? 'pdo_mysql' : ''))));
        $checks[] = array('label' => 'OpenSSL (凭据加密)', 'ok' => extension_loaded('openssl'), 'value' => extension_loaded('openssl') ? '已启用' : '缺失');
        $checks[] = array('label' => 'cURL (调用 Mailu API)', 'ok' => extension_loaded('curl') || ini_get('allow_url_fopen'), 'value' => extension_loaded('curl') ? 'cURL' : (ini_get('allow_url_fopen') ? 'allow_url_fopen' : '缺失'));
        $checks[] = array('label' => 'JSON 扩展', 'ok' => extension_loaded('json'), 'value' => extension_loaded('json') ? '已启用' : '缺失');
        $checks[] = array('label' => 'config 目录可写', 'ok' => is_writable(dirname(CONFIG_PATH)), 'value' => dirname(CONFIG_PATH));
        $checks[] = array('label' => 'storage 目录可写', 'ok' => is_writable(STORAGE_PATH), 'value' => STORAGE_PATH);
        $checks[] = array('label' => 'ZipArchive (打包下载)', 'ok' => class_exists('ZipArchive'), 'value' => class_exists('ZipArchive') ? '已启用' : '可选，缺失时只提供单文件脚本');
        return $checks;
    }

    public function form()
    {
        $this->render('setup/form', array(
            'title' => '初始化中台',
            'checks' => $this->checks(),
            'defaults' => array(
                'app_name' => 'Mailu 邮件中台',
                'db_driver' => extension_loaded('pdo_sqlite') ? 'sqlite' : 'mysql',
                'db_database' => 'mailu.sqlite',
                'timezone' => 'Asia/Shanghai',
                'base_path' => Request::basePath(),
            ),
        ), 'auth_layout');
    }

    public function install()
    {
        $input = $this->input();
        $validator = Validator::make($input, array(
            'app_name' => 'required|max:64',
            'admin_username' => 'required|min:3|max:32',
            'admin_password' => 'required|min:10',
            'admin_password_confirm' => 'required|same:admin_password',
        ), array(
            'app_name' => '中台名称',
            'admin_username' => '管理员账号',
            'admin_password' => '管理员密码',
            'admin_password_confirm' => '确认密码',
        ));
        if ($validator->fails()) {
            $errors = $validator->errors();
            Flash::withInput($input, $errors);
            Flash::error(reset($errors));
            $this->redirect('/setup');
        }
        try {
            $driver = Request::str('db_driver', 'sqlite') === 'mysql' ? 'mysql' : 'sqlite';
            $config = array(
                'app' => array(
                    'name' => Request::str('app_name', 'Mailu 邮件中台'),
                    'version' => MC_VERSION,
                    'key' => Crypto::randomKey(32),
                    'debug' => false,
                    'base_path' => trim(Request::str('base_path', ''), '/'),
                    'timezone' => Request::str('timezone', 'Asia/Shanghai'),
                    'url' => '',
                ),
                'db' => array(
                    'driver' => $driver,
                    'database' => $driver === 'mysql' ? Request::str('db_name', 'mailu_console') : Request::str('db_database', 'mailu.sqlite'),
                    'host' => Request::str('db_host', '127.0.0.1'),
                    'port' => Request::int('db_port', 3306),
                    'username' => Request::str('db_username', ''),
                    'password' => (string) Request::input('db_password', ''),
                    'prefix' => 'mu_',
                ),
                'session' => array('name' => 'mailu_console_sid', 'lifetime' => 240),
                'mailu' => array(
                    'api_path' => '/api/v1',
                    'timeout' => 20,
                    'default_quota_mb' => 2048,
                    'default_password_length' => 16,
                    'sync_user_limit' => 2000,
                ),
                'deploy' => array(
                    'mailu_version' => (string) Request::str('mailu_version', '2024.06.61'),
                    'mirror_prefix' => Request::str('mirror_prefix', ''),
                    'allow_remote_deploy' => false,
                    'ssh_binary' => 'ssh',
                    'scp_binary' => 'scp',
                ),
                'security' => array('allow_api' => true, 'password_min_length' => 10),
            );
            if (!Config::write(CONFIG_PATH, $config)) {
                throw new \RuntimeException('无法写入 config/config.php，请检查目录权限');
            }
            Config::load(CONFIG_PATH);
            Logger::info('console installed', array('driver' => $driver));
            Database::migrate();
            @file_put_contents(STORAGE_PATH . '/schema.lock', MC_SCHEMA_VERSION);
            Auth::createAdmin(Request::str('admin_username'), (string) Request::input('admin_password', ''), Request::str('admin_email', ''));
            $apiToken = random_token(24);
            \App\Core\Settings::set('api.bearer_hash', hash('sha256', $apiToken));
            \App\Core\Settings::set('api.bearer_token', $apiToken);
            \App\Core\Settings::set('app.installed_at', date('Y-m-d H:i:s'));
            \App\Core\Settings::set('app.console_url', $this->consoleUrl());
            Flash::success('初始化完成！请使用刚创建的管理员账号登录。');
            Flash::add('info', '中台 API 令牌（请妥善保存）: ' . $apiToken);
            $this->redirect('/login');
        } catch (\Throwable $e) {
            Logger::error('install failed', array('error' => $e->getMessage()));
            Flash::withInput($input);
            Flash::error('初始化失败: ' . $e->getMessage());
            $this->redirect('/setup');
        }
    }

    private function consoleUrl()
    {
        $scheme = Request::isSecure() ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        return $scheme . '://' . $host . Request::basePath();
    }
}