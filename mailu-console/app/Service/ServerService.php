<?php
/**
 * Mail server (Mailu instance) registry + health checks.
 */

namespace App\Service;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Logger;

final class ServerService
{
    public static function all(array $filters = array())
    {
        $where = array('1=1');
        $params = array();
        if (!empty($filters['enabled'])) {
            $where[] = 'enabled = 1';
        }
        if (!empty($filters['q'])) {
            $where[] = '(name LIKE :q OR base_url LIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }
        $rows = Database::fetchAll('SELECT * FROM {servers} WHERE ' . implode(' AND ', $where) . ' ORDER BY name ASC', $params);
        foreach ($rows as $index => $row) {
            $rows[$index]['domain_count'] = (int) Database::value('SELECT COUNT(*) FROM {domains} WHERE server_id = ?', array((int) $row['id']), 0);
            $rows[$index]['user_count'] = (int) Database::value('SELECT COUNT(*) FROM {users} WHERE server_id = ?', array((int) $row['id']), 0);
            $rows[$index]['alias_count'] = (int) Database::value('SELECT COUNT(*) FROM {aliases} WHERE server_id = ?', array((int) $row['id']), 0);
        }
        return $rows;
    }

    public static function find($id)
    {
        return Database::fetch('SELECT * FROM {servers} WHERE id = ?', array((int) $id));
    }

    public static function findOrFail($id)
    {
        $server = self::find($id);
        if (!$server) {
            throw new \RuntimeException('邮件服务器不存在: #' . (int) $id);
        }
        return $server;
    }

    public static function create(array $data)
    {
        $id = Database::insert('servers', array(
            'name' => $data['name'],
            'base_url' => rtrim($data['base_url'], '/'),
            'admin_url' => $data['admin_url'],
            'webmail_url' => $data['webmail_url'],
            'api_path' => $data['api_path'],
            'auth_mode' => $data['auth_mode'],
            'api_token_enc' => Crypto::encrypt($data['api_token']),
            'api_token_hint' => $data['api_token'] !== '' ? Crypto::mask($data['api_token']) : '',
            'api_user_enc' => Crypto::encrypt($data['api_user']),
            'api_pass_enc' => Crypto::encrypt($data['api_pass']),
            'verify_tls' => $data['verify_tls'] ? 1 : 0,
            'timeout' => (int) $data['timeout'],
            'imap_host' => $data['imap_host'], 'imap_port' => (int) $data['imap_port'], 'imap_security' => $data['imap_security'],
            'pop3_host' => $data['pop3_host'], 'pop3_port' => (int) $data['pop3_port'], 'pop3_security' => $data['pop3_security'],
            'smtp_host' => $data['smtp_host'], 'smtp_port' => (int) $data['smtp_port'], 'smtp_security' => $data['smtp_security'],
            'sieve_host' => $data['sieve_host'], 'sieve_port' => (int) $data['sieve_port'],
            'dkim_selector' => $data['dkim_selector'],
            'note' => $data['note'],
            'enabled' => $data['enabled'] ? 1 : 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ));
        AuditService::log('server.create', 'ok', array('name' => $data['name'], 'base_url' => $data['base_url']), array(
            'server_id' => $id, 'target_type' => 'server', 'target_label' => $data['name'],
        ));
        return $id;
    }

    public static function update($id, array $data)
    {
        $server = self::findOrFail($id);
        $update = array(
            'name' => $data['name'],
            'base_url' => rtrim($data['base_url'], '/'),
            'admin_url' => $data['admin_url'],
            'webmail_url' => $data['webmail_url'],
            'api_path' => $data['api_path'],
            'auth_mode' => $data['auth_mode'],
            'verify_tls' => $data['verify_tls'] ? 1 : 0,
            'timeout' => (int) $data['timeout'],
            'imap_host' => $data['imap_host'], 'imap_port' => (int) $data['imap_port'], 'imap_security' => $data['imap_security'],
            'pop3_host' => $data['pop3_host'], 'pop3_port' => (int) $data['pop3_port'], 'pop3_security' => $data['pop3_security'],
            'smtp_host' => $data['smtp_host'], 'smtp_port' => (int) $data['smtp_port'], 'smtp_security' => $data['smtp_security'],
            'sieve_host' => $data['sieve_host'], 'sieve_port' => (int) $data['sieve_port'],
            'dkim_selector' => $data['dkim_selector'],
            'note' => $data['note'],
            'enabled' => $data['enabled'] ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        );
        // only replace secrets when a new value was typed
        if (!empty($data['api_token'])) {
            $update['api_token_enc'] = Crypto::encrypt($data['api_token']);
            $update['api_token_hint'] = Crypto::mask($data['api_token']);
        }
        if (!empty($data['api_user'])) {
            $update['api_user_enc'] = Crypto::encrypt($data['api_user']);
        }
        if (!empty($data['api_pass'])) {
            $update['api_pass_enc'] = Crypto::encrypt($data['api_pass']);
        }
        Database::update('servers', $update, 'id = :id', array('id' => (int) $id));
        AuditService::log('server.update', 'ok', array('name' => $data['name']), array(
            'server_id' => (int) $id, 'target_type' => 'server', 'target_label' => $data['name'],
        ));
        return true;
    }

    public static function delete($id, $purgeLocal = true)
    {
        $server = self::findOrFail($id);
        if ($purgeLocal) {
            Database::delete('aliases', 'server_id = :sid', array('sid' => (int) $id));
            Database::delete('users', 'server_id = :sid', array('sid' => (int) $id));
            Database::delete('domains', 'server_id = :sid', array('sid' => (int) $id));
        }
        Database::delete('servers', 'id = :id', array('id' => (int) $id));
        AuditService::log('server.delete', 'ok', array('purge_local' => (bool) $purgeLocal), array(
            'server_id' => (int) $id, 'target_type' => 'server', 'target_label' => $server['name'],
        ));
        return true;
    }

    public static function client($server)
    {
        if (!is_array($server)) {
            $server = self::findOrFail($server);
        }
        return new MailuClient(
            $server,
            Crypto::decrypt(isset($server['api_token_enc']) ? $server['api_token_enc'] : ''),
            Crypto::decrypt(isset($server['api_user_enc']) ? $server['api_user_enc'] : ''),
            Crypto::decrypt(isset($server['api_pass_enc']) ? $server['api_pass_enc'] : '')
        );
    }

    /**
     * Connectivity + TLS check. Returns ['ok'=>bool,'api'=>array,'cert'=>array,'message'=>string].
     */
    public static function check($id, $persist = true)
    {
        $server = self::findOrFail($id);
        $client = self::client($server);
        $api = $client->ping();
        $host = parse_url($server['base_url'], PHP_URL_HOST);
        $port = (int) parse_url($server['base_url'], PHP_URL_PORT);
        if ($port <= 0) {
            $port = strpos($server['base_url'], 'https://') === 0 ? 443 : 80;
        }
        $cert = null;
        if ($host && $port === 443) {
            $cert = self::certInfo($host, $port);
        }
        $ok = (bool) $api['ok'];
        $message = $ok ? '连接正常' : $api['message'];
        if ($persist) {
            Database::update('servers', array(
                'last_check_at' => date('Y-m-d H:i:s'),
                'last_check_ok' => $ok ? 1 : 0,
                'last_check_msg' => str_limit($message, 250, ''),
                'cert_expires_at' => $cert ? date('Y-m-d H:i:s', $cert['valid_to']) : null,
            ), 'id = :id', array('id' => (int) $id));
            AuditService::log('server.check', $ok ? 'ok' : 'failed', array('cert' => $cert), array(
                'server_id' => (int) $id, 'target_type' => 'server', 'target_label' => $server['name'], 'message' => $message,
            ));
        }
        return array('ok' => $ok, 'api' => $api, 'cert' => $cert, 'message' => $message, 'server' => $server);
    }

    /** Read the TLS certificate of a host (used for expiry warnings). */
    public static function certInfo($host, $port = 443, $timeout = 8)
    {
        if (!function_exists('openssl_x509_parse')) {
            return null;
        }
        $context = stream_context_create(array('ssl' => array(
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'capture_peer_cert' => true,
            'SNI_enabled' => true,
            'peer_name' => $host,
        )));
        $client = @stream_socket_client('ssl://' . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if (!$client) {
            Logger::warn('tls probe failed', array('host' => $host, 'error' => $errstr));
            return null;
        }
        $params = stream_context_get_params($client);
        @fclose($client);
        $cert = isset($params['options']['ssl']['peer_certificate']) ? $params['options']['ssl']['peer_certificate'] : null;
        if (!$cert) {
            return null;
        }
        $parsed = openssl_x509_parse($cert);
        if (!is_array($parsed)) {
            return null;
        }
        return array(
            'subject' => isset($parsed['subject']['CN']) ? $parsed['subject']['CN'] : '',
            'issuer' => isset($parsed['issuer']['CN']) ? $parsed['issuer']['CN'] : '',
            'valid_from' => isset($parsed['validFrom_time_t']) ? (int) $parsed['validFrom_time_t'] : 0,
            'valid_to' => isset($parsed['validTo_time_t']) ? (int) $parsed['validTo_time_t'] : 0,
            'days_left' => isset($parsed['validTo_time_t']) ? (int) floor(((int) $parsed['validTo_time_t'] - time()) / 86400) : 0,
        );
    }

    public static function stats()
    {
        return array(
            'servers' => (int) Database::value('SELECT COUNT(*) FROM {servers}', array(), 0),
            'servers_ok' => (int) Database::value('SELECT COUNT(*) FROM {servers} WHERE last_check_ok = 1', array(), 0),
            'domains' => (int) Database::value('SELECT COUNT(*) FROM {domains}', array(), 0),
            'users' => (int) Database::value('SELECT COUNT(*) FROM {users}', array(), 0),
            'users_enabled' => (int) Database::value('SELECT COUNT(*) FROM {users} WHERE enabled = 1', array(), 0),
            'aliases' => (int) Database::value('SELECT COUNT(*) FROM {aliases}', array(), 0),
            'quota_bytes' => (int) Database::value('SELECT COALESCE(SUM(quota_bytes),0) FROM {users}', array(), 0),
            'quota_used' => (int) Database::value('SELECT COALESCE(SUM(quota_bytes_used),0) FROM {users}', array(), 0),
            'passwords_known' => (int) Database::value("SELECT COUNT(*) FROM {users} WHERE password_enc IS NOT NULL AND password_enc <> ''", array(), 0),
        );
    }

    /** Form defaults for a new server. */
    public static function defaults()
    {
        return array(
            'name' => '',
            'base_url' => '',
            'admin_url' => '',
            'webmail_url' => '',
            'api_path' => (string) Config::get('mailu.api_path', '/api/v1'),
            'auth_mode' => 'bearer',
            'api_token' => '',
            'api_user' => '',
            'api_pass' => '',
            'verify_tls' => 1,
            'timeout' => (int) Config::get('mailu.timeout', 20),
            'imap_host' => '', 'imap_port' => 993, 'imap_security' => 'ssl',
            'pop3_host' => '', 'pop3_port' => 995, 'pop3_security' => 'ssl',
            'smtp_host' => '', 'smtp_port' => 465, 'smtp_security' => 'ssl',
            'sieve_host' => '', 'sieve_port' => 4190,
            'dkim_selector' => 'dkim',
            'note' => '',
            'enabled' => 1,
        );
    }

    /** Fill host/port defaults from the base URL of the instance. */
    public static function deriveMailDefaults(array $data)
    {
        $host = parse_url($data['base_url'], PHP_URL_HOST);
        if ($host) {
            foreach (array('imap_host', 'pop3_host', 'smtp_host', 'sieve_host') as $key) {
                if (empty($data[$key])) {
                    $data[$key] = $host;
                }
            }
        }
        if (empty($data['admin_url']) && $host) {
            $data['admin_url'] = 'https://' . $host . '/admin';
        }
        if (empty($data['webmail_url']) && $host) {
            $data['webmail_url'] = 'https://' . $host . '/webmail';
        }
        return $data;
    }
}