<?php
/**
 * Generates the per-server Linux installation script from the packaged
 * single-file deploy bundle (resources/mailu-deploy.bundle.sh).
 *
 * The generated script is self contained: it detects the distribution,
 * installs Docker + the compose plugin, downloads the official Mailu package,
 * renders mailu.env, configures TLS, the firewall and finally starts the stack.
 */

namespace App\Service;

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;

final class DeployScriptService
{
    public static function bundlePath()
    {
        return BASE_PATH . '/resources/mailu-deploy.bundle.sh';
    }

    public static function bundleContent()
    {
        $path = self::bundlePath();
        if (!is_file($path)) {
            return null;
        }
        return (string) file_get_contents($path);
    }

    public static function bundleAvailable()
    {
        return self::bundleContent() !== null;
    }

    public static function paramsMarker()
    {
        return '##MC_PARAMS##';
    }

    public static function defaultParams(array $server = null, array $overrides = array())
    {
        $host = '';
        $token = '';
        if ($server) {
            $host = (string) parse_url($server['base_url'], PHP_URL_HOST);
            $token = Crypto::decrypt(isset($server['api_token_enc']) ? $server['api_token_enc'] : '');
        }
        $domain = '';
        if ($server) {
            $firstDomain = Database::fetch('SELECT name FROM {domains} WHERE server_id = ? ORDER BY id ASC LIMIT 1', array((int) $server['id']));
            if ($firstDomain) {
                $domain = (string) $firstDomain['name'];
            }
        }
        $params = array(
            'MAILU_DOMAIN' => $domain,
            'MAILU_HOSTNAMES' => $host !== '' ? $host : 'mail.example.com',
            'MAILU_TLS' => 'letsencrypt',
            'MAILU_ADMIN_USER' => 'admin',
            'MAILU_ADMIN_PASS' => random_password(18),
            'MAILU_POSTMASTER' => 'postmaster',
            'MAILU_WEBMAIL' => 'snappymail',
            'MAILU_WEBMAIL_IMAGE' => '',
            'MAILU_ADMIN_GUI' => 'true',
            'MAILU_API' => 'true',
            'MAILU_API_TOKEN' => $token !== '' ? $token : random_token(16),
            'MAILU_VERSION' => (string) Config::get('deploy.mailu_version', '2024.06.61'),
            'MAILU_MIRROR_PREFIX' => (string) Config::get('deploy.mirror_prefix', ''),
            'MAILU_ROOT' => '/mailu',
            'MAILU_SUBNET' => '192.168.203.0/24',
            'MAILU_SECRET_KEY' => random_token(16),
            'MAILU_BIND_ADDRESS4' => '0.0.0.0',
            'MAILU_SITE_NAME' => 'Mailu Mail',
            'MAILU_MESSAGE_SIZE_LIMIT' => '52428800',
            'MAILU_MESSAGE_RATELIMIT' => '200/day',
            'MAILU_ANTIVIRUS' => 'none',
            'MAILU_WEBDAV' => 'none',
            'MAILU_INSTALL_DOCKER' => 'yes',
            'MAILU_CONFIGURE_FIREWALL' => 'yes',
            'MAILU_FIREWALL_MANAGER' => 'auto',
            'MAILU_CERTBOT_EMAIL' => 'admin@' . ($domain !== '' ? $domain : 'example.com'),
            'MAILU_LANG' => 'zh',
            'MAILU_COUNTRY' => 'CN',
            'MAILU_CONSOLE_URL' => self::consoleUrl(),
            'MAILU_DEFAULT_QUOTA_MB' => (string) Config::get('mailu.default_quota_mb', 2048),
            'MAILU_BACKUP_DIR' => '/var/backups/mailu',
            'MAILU_DRY_RUN' => 'no',
        );
        foreach ($overrides as $key => $value) {
            $params[$key] = $value;
        }
        return $params;
    }

    public static function consoleUrl()
    {
        $configured = (string) Config::get('app.url', '');
        if ($configured !== '') {
            return rtrim($configured, '/');
        }
        $scheme = \App\Core\Request::isSecure() ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        return $scheme . '://' . $host . \App\Core\Request::basePath();
    }

    /** Build a bash parameter block from the param array. */
    public static function paramsBlock(array $params)
    {
        $lines = array();
        $lines[] = '# ---------------------------------------------------------------------------';
        $lines[] = '# 中台生成的部署参数（可直接修改后重新运行本脚本）';
        $lines[] = '# ---------------------------------------------------------------------------';
        foreach ($params as $key => $value) {
            if (!preg_match('/^[A-Z0-9_]+$/', $key)) {
                continue;
            }
            $lines[] = $key . '=' . self::shQuote((string) $value);
        }
        return implode("\n", $lines);
    }

    public static function shQuote($value)
    {
        return "'" . str_replace("'", "'\\''", (string) $value) . "'";
    }

    public static function fromInput(array $input, array $server = null)
    {
        $params = self::defaultParams($server);
        $map = array(
            'domain' => 'MAILU_DOMAIN',
            'hostnames' => 'MAILU_HOSTNAMES',
            'tls' => 'MAILU_TLS',
            'admin_user' => 'MAILU_ADMIN_USER',
            'admin_pass' => 'MAILU_ADMIN_PASS',
            'postmaster' => 'MAILU_POSTMASTER',
            'webmail' => 'MAILU_WEBMAIL',
            'webmail_image' => 'MAILU_WEBMAIL_IMAGE',
            'admin_gui' => 'MAILU_ADMIN_GUI',
            'api' => 'MAILU_API',
            'api_token' => 'MAILU_API_TOKEN',
            'version' => 'MAILU_VERSION',
            'mirror_prefix' => 'MAILU_MIRROR_PREFIX',
            'root' => 'MAILU_ROOT',
            'subnet' => 'MAILU_SUBNET',
            'secret_key' => 'MAILU_SECRET_KEY',
            'bind_address' => 'MAILU_BIND_ADDRESS4',
            'site_name' => 'MAILU_SITE_NAME',
            'message_size_limit' => 'MAILU_MESSAGE_SIZE_LIMIT',
            'message_ratelimit' => 'MAILU_MESSAGE_RATELIMIT',
            'antivirus' => 'MAILU_ANTIVIRUS',
            'webdav' => 'MAILU_WEBDAV',
            'install_docker' => 'MAILU_INSTALL_DOCKER',
            'firewall' => 'MAILU_CONFIGURE_FIREWALL',
            'firewall_manager' => 'MAILU_FIREWALL_MANAGER',
            'certbot_email' => 'MAILU_CERTBOT_EMAIL',
            'lang' => 'MAILU_LANG',
            'country' => 'MAILU_COUNTRY',
            'default_quota_mb' => 'MAILU_DEFAULT_QUOTA_MB',
            'backup_dir' => 'MAILU_BACKUP_DIR',
        );
        foreach ($map as $field => $key) {
            if (array_key_exists($field, $input)) {
                $value = $input[$field];
                if (in_array($key, array('MAILU_ADMIN_GUI', 'MAILU_API', 'MAILU_INSTALL_DOCKER', 'MAILU_CONFIGURE_FIREWALL'), true)) {
                    $value = $value ? 'yes' : 'no';
                    if ($key === 'MAILU_ADMIN_GUI' || $key === 'MAILU_API') {
                        $value = $value === 'yes' ? 'true' : 'false';
                    }
                }
                if (is_array($value)) {
                    $value = implode(',', $value);
                }
                $params[$key] = (string) $value;
            }
        }
        // sensible fallbacks
        if ($params['MAILU_DOMAIN'] === '') {
            throw new \RuntimeException('请填写主域名 (MAILU_DOMAIN)');
        }
        if ($params['MAILU_HOSTNAMES'] === '') {
            throw new \RuntimeException('请填写邮件服务器主机名 (MAILU_HOSTNAMES)，例如 mail.example.com');
        }
        if ($params['MAILU_ADMIN_PASS'] === '' || strlen($params['MAILU_ADMIN_PASS']) < 8) {
            $params['MAILU_ADMIN_PASS'] = random_password(18);
        }
        if ($params['MAILU_SECRET_KEY'] === '' || strlen($params['MAILU_SECRET_KEY']) < 16) {
            $params['MAILU_SECRET_KEY'] = random_token(16);
        }
        if ($params['MAILU_API_TOKEN'] === '' || strlen($params['MAILU_API_TOKEN']) < 8) {
            $params['MAILU_API_TOKEN'] = random_token(16);
        }
        if ($params['MAILU_CERTBOT_EMAIL'] === '' || strpos($params['MAILU_CERTBOT_EMAIL'], '@') === false) {
            $params['MAILU_CERTBOT_EMAIL'] = 'admin@' . $params['MAILU_DOMAIN'];
        }
        if (!in_array($params['MAILU_TLS'], array('letsencrypt', 'cert', 'notls', 'mail', 'mail-letsencrypt'), true)) {
            $params['MAILU_TLS'] = 'letsencrypt';
        }
        if (!in_array($params['MAILU_WEBMAIL'], array('snappymail', 'roundcube', 'none'), true)) {
            $params['MAILU_WEBMAIL'] = 'snappymail';
        }
        return $params;
    }

    /** Produce the final, self-contained bash script. */
    public static function render(array $params, array $meta = array())
    {
        $bundle = self::bundleContent();
        $block = self::paramsBlock($params);
        if ($bundle === null) {
            throw new \RuntimeException('部署脚本模板缺失：resources/mailu-deploy.bundle.sh 不存在，请重新解压完整安装包。');
        }
        $header = array();
        $header[] = '#!/usr/bin/env bash';
        $header[] = '# ===========================================================================';
        $header[] = '# Mailu 一键部署脚本（由 Mailu 邮件中台生成）';
        $header[] = '# 生成时间: ' . date('Y-m-d H:i:s');
        $header[] = '# 生成者  : ' . (isset($meta['author']) ? $meta['author'] : 'console');
        $header[] = '# 主域名  : ' . $params['MAILU_DOMAIN'];
        $header[] = '# 主机名  : ' . $params['MAILU_HOSTNAMES'];
        $header[] = '# 用途    : 在 Linux VPS 上安装/升级 Mailu 邮件系统 (Docker Compose)';
        $header[] = '# 用法    : bash ' . (isset($meta['filename']) ? $meta['filename'] : 'mailu-install.sh') . ' --yes';
        $header[] = '# 支持    : Debian/Ubuntu, RHEL/CentOS/Rocky/Alma/Fedora/Amazon, openSUSE, Alpine, Arch';
        $header[] = '# ===========================================================================';
        $lines = explode("\n", $bundle);
        // drop the original shebang / preamble of the bundle
        foreach ($lines as $index => $line) {
            if (strpos($line, '#!/') === 0) {
                $lines[$index] = '';
            }
        }
        $bundle = implode("\n", $lines);
        $marker = self::paramsMarker();
        if (strpos($bundle, $marker) !== false) {
            $bundle = str_replace($marker, $block, $bundle);
        } else {
            $bundle = $block . "\n" . $bundle;
        }
        return implode("\n", $header) . "\n\n" . $bundle . "\n";
    }

    public static function generate(array $params, array $meta = array())
    {
        $domain = preg_replace('/[^a-z0-9.\-]/i', '', $params['MAILU_DOMAIN']);
        $filename = 'mailu-install-' . ($domain !== '' ? $domain : 'server') . '-' . date('Ymd-His') . '.sh';
        $meta['filename'] = $filename;
        $content = self::render($params, $meta);
        $dir = STORAGE_PATH . '/deploy';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        @file_put_contents($dir . '/' . $filename, $content, LOCK_EX);
        $id = Database::insert('deploy_scripts', array(
            'server_id' => isset($meta['server_id']) ? (int) $meta['server_id'] : null,
            'name' => isset($meta['name']) ? $meta['name'] : ('部署脚本 ' . $domain),
            'filename' => $filename,
            'params_json' => json_encode($params, JSON_UNESCAPED_UNICODE),
            'sha256' => hash('sha256', $content),
            'size' => strlen($content),
            'content' => $content,
            'created_by' => isset($meta['author']) ? $meta['author'] : 'console',
            'created_at' => date('Y-m-d H:i:s'),
        ));
        AuditService::log('deploy.generate', 'ok', array('domain' => $params['MAILU_DOMAIN'], 'tls' => $params['MAILU_TLS']), array(
            'server_id' => isset($meta['server_id']) ? (int) $meta['server_id'] : null,
            'target_type' => 'deploy',
            'target_label' => $filename,
        ));
        return array('id' => $id, 'filename' => $filename, 'content' => $content, 'sha256' => hash('sha256', $content), 'params' => $params);
    }

    public static function all($limit = 50)
    {
        return Database::fetchAll(
            'SELECT d.*, s.name AS server_name FROM {deploy_scripts} d LEFT JOIN {servers} s ON s.id = d.server_id ORDER BY d.id DESC LIMIT ' . (int) $limit
        );
    }

    public static function find($id)
    {
        return Database::fetch('SELECT * FROM {deploy_scripts} WHERE id = ?', array((int) $id));
    }

    public static function delete($id)
    {
        $script = self::find($id);
        if ($script) {
            $file = STORAGE_PATH . '/deploy/' . $script['filename'];
            if (is_file($file)) {
                @unlink($file);
            }
            Database::delete('deploy_scripts', 'id = :id', array('id' => (int) $id));
            AuditService::log('deploy.delete', 'ok', array(), array('target_type' => 'deploy', 'target_label' => $script['filename']));
        }
        return true;
    }

    /** Zip of the deploy package (only when ZipArchive is available). */
    public static function packageZip()
    {
        $deployDir = dirname(BASE_PATH) . '/deploy';
        if (!class_exists('ZipArchive') || !is_dir($deployDir)) {
            return null;
        }
        $tmp = STORAGE_PATH . '/cache/mailu-deploy-package.zip';
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return null;
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($deployDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $local = 'mailu-deploy/' . ltrim(str_replace($deployDir, '', $file->getPathname()), '/\\');
            $zip->addFile($file->getPathname(), str_replace('\\', '/', $local));
        }
        $bundle = self::bundlePath();
        if (is_file($bundle)) {
            $zip->addFile($bundle, 'mailu-deploy/mailu-deploy.bundle.sh');
        }
        $zip->close();
        return is_file($tmp) ? $tmp : null;
    }
}