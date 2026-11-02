<?php
/**
 * Pulls domains / users / aliases from every Mailu instance into the local
 * mirror so the console can list, search and audit them quickly.
 */

namespace App\Service;

use App\Core\Database;
use App\Core\Logger;

final class SyncService
{
    public static function syncAll($scope = 'all')
    {
        $summary = array();
        $servers = Database::fetchAll('SELECT * FROM {servers} WHERE enabled = 1 ORDER BY name');
        foreach ($servers as $server) {
            $summary[] = self::syncServer((int) $server['id'], $scope);
        }
        return $summary;
    }

    public static function syncServer($serverId, $scope = 'all')
    {
        $started = date('Y-m-d H:i:s');
        $runId = Database::insert('sync_runs', array(
            'server_id' => (int) $serverId,
            'scope' => $scope,
            'status' => 'running',
            'started_at' => $started,
        ));
        $stats = array('domains' => 0, 'users' => 0, 'aliases' => 0, 'removed' => 0);
        $server = ServerService::find($serverId);
        $status = 'ok';
        $message = '';
        try {
            if (!$server) {
                throw new \RuntimeException('邮件服务器不存在');
            }
            $client = ServerService::client($server);
            if ($scope === 'all' || $scope === 'domains' || $scope === 'users' || $scope === 'aliases') {
                $stats = array_merge($stats, self::syncDomains($client, $serverId));
            }
            if ($scope === 'all' || $scope === 'users') {
                $stats = array_merge($stats, self::syncUsers($client, $serverId));
            }
            if ($scope === 'all' || $scope === 'aliases') {
                $stats = array_merge($stats, self::syncAliases($client, $serverId));
            }
            Database::update('servers', array('last_sync_at' => date('Y-m-d H:i:s')), 'id = :id', array('id' => (int) $serverId));
        } catch (MailuException $e) {
            $status = 'failed';
            $message = $e->getMessage();
            Logger::error('sync failed', array('server_id' => $serverId, 'error' => $message));
        } catch (\Throwable $e) {
            $status = 'failed';
            $message = $e->getMessage();
            Logger::error('sync error', array('server_id' => $serverId, 'error' => $message));
        }
        Database::update('sync_runs', array(
            'status' => $status,
            'stats_json' => json_encode($stats, JSON_UNESCAPED_UNICODE),
            'message' => $message,
            'finished_at' => date('Y-m-d H:i:s'),
        ), 'id = :id', array('id' => $runId));
        AuditService::log('server.sync', $status === 'ok' ? 'ok' : 'failed', $stats, array(
            'server_id' => (int) $serverId,
            'target_type' => 'server',
            'target_label' => $server ? $server['name'] : ('#' . $serverId),
            'message' => $message,
        ));
        return array('server_id' => (int) $serverId, 'server' => $server ? $server['name'] : '', 'status' => $status, 'message' => $message, 'stats' => $stats);
    }

    private static function syncDomains(MailuClient $client, $serverId)
    {
        $remote = $client->listDomains();
        $seen = array();
        foreach ($remote as $item) {
            $name = isset($item['name']) ? strtolower(trim((string) $item['name'])) : '';
            if ($name === '') {
                continue;
            }
            $seen[] = $name;
            $detail = is_array($item) ? $item : array();
            // make sure we have the full record (dns_* fields are only in the detail endpoint)
            if (!isset($detail['dns_mx'])) {
                $full = $client->getDomain($name);
                if (is_array($full)) {
                    $detail = array_merge($detail, $full);
                }
            }
            self::upsertDomain($serverId, $name, $detail);
        }
        $removed = self::markMissing('domains', $serverId, $seen, 'name');
        return array('domains' => count($seen), 'domains_removed' => $removed);
    }

    private static function upsertDomain($serverId, $name, array $detail)
    {
        $existing = Database::fetch('SELECT id FROM {domains} WHERE server_id = ? AND name = ?', array((int) $serverId, $name));
        $data = array(
            'server_id' => (int) $serverId,
            'name' => $name,
            'comment' => isset($detail['comment']) ? $detail['comment'] : '',
            'max_users' => isset($detail['max_users']) ? (int) $detail['max_users'] : -1,
            'max_aliases' => isset($detail['max_aliases']) ? (int) $detail['max_aliases'] : -1,
            'max_quota_bytes' => isset($detail['max_quota_bytes']) ? (int) $detail['max_quota_bytes'] : 0,
            'signup_enabled' => !empty($detail['signup_enabled']) ? 1 : 0,
            'alternatives' => isset($detail['alternatives']) ? json_encode($detail['alternatives'], JSON_UNESCAPED_UNICODE) : null,
            'dns_json' => json_encode(array(
                'mx' => isset($detail['dns_mx']) ? $detail['dns_mx'] : '',
                'spf' => isset($detail['dns_spf']) ? $detail['dns_spf'] : '',
                'dkim' => isset($detail['dns_dkim']) ? $detail['dns_dkim'] : '',
                'dmarc' => isset($detail['dns_dmarc']) ? $detail['dns_dmarc'] : '',
                'dmarc_report' => isset($detail['dns_dmarc_report']) ? $detail['dns_dmarc_report'] : '',
                'autoconfig' => isset($detail['dns_autoconfig']) ? $detail['dns_autoconfig'] : array(),
                'tlsa' => isset($detail['dns_tlsa']) ? $detail['dns_tlsa'] : array(),
            ), JSON_UNESCAPED_UNICODE),
            'remote_exists' => 1,
            'synced_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        );
        if ($existing) {
            Database::update('domains', $data, 'id = :id', array('id' => (int) $existing['id']));
            return (int) $existing['id'];
        }
        $data['created_at'] = date('Y-m-d H:i:s');
        return Database::insert('domains', $data);
    }

    private static function syncUsers(MailuClient $client, $serverId)
    {
        $limit = (int) \App\Core\Config::get('mailu.sync_user_limit', 2000);
        $remote = $client->listUsers();
        $seen = array();
        $count = 0;
        foreach ($remote as $item) {
            $email = isset($item['email']) ? strtolower(trim((string) $item['email'])) : '';
            if ($email === '') {
                continue;
            }
            if ($limit > 0 && $count >= $limit) {
                Logger::warn('user sync truncated', array('server_id' => $serverId, 'limit' => $limit));
                break;
            }
            $seen[] = $email;
            $count++;
            self::upsertUser($serverId, $email, $item);
        }
        $removed = self::markMissing('users', $serverId, $seen);
        return array('users' => $count, 'users_removed' => $removed);
    }

    private static function upsertUser($serverId, $email, array $detail)
    {
        $parts = explode('@', $email);
        $domainName = count($parts) > 1 ? $parts[1] : '';
        $domainId = $domainName !== '' ? Database::value('SELECT id FROM {domains} WHERE server_id = ? AND name = ?', array((int) $serverId, $domainName), null) : null;
        $data = array(
            'server_id' => (int) $serverId,
            'domain_id' => $domainId ? (int) $domainId : null,
            'email' => $email,
            'local_part' => $parts[0],
            'domain_name' => $domainName,
            'displayed_name' => isset($detail['displayed_name']) ? $detail['displayed_name'] : '',
            'comment' => isset($detail['comment']) ? $detail['comment'] : '',
            'quota_bytes' => isset($detail['quota_bytes']) ? (int) $detail['quota_bytes'] : 0,
            'quota_bytes_used' => isset($detail['quota_bytes_used']) ? (int) $detail['quota_bytes_used'] : 0,
            'enabled' => !empty($detail['enabled']) ? 1 : 0,
            'global_admin' => !empty($detail['global_admin']) ? 1 : 0,
            'enable_imap' => array_key_exists('enable_imap', $detail) ? (!empty($detail['enable_imap']) ? 1 : 0) : 1,
            'enable_pop' => array_key_exists('enable_pop', $detail) ? (!empty($detail['enable_pop']) ? 1 : 0) : 1,
            'forward_enabled' => !empty($detail['forward_enabled']) ? 1 : 0,
            'forward_destination' => isset($detail['forward_destination']) ? json_encode($detail['forward_destination'], JSON_UNESCAPED_UNICODE) : null,
            'spam_enabled' => array_key_exists('spam_enabled', $detail) ? (!empty($detail['spam_enabled']) ? 1 : 0) : 1,
            'spam_threshold' => isset($detail['spam_threshold']) ? (int) $detail['spam_threshold'] : null,
            'remote_exists' => 1,
            'synced_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        );
        $existing = Database::fetch('SELECT id, password_enc, password_source FROM {users} WHERE server_id = ? AND email = ?', array((int) $serverId, $email));
        if ($existing) {
            if (empty($existing['password_source'])) {
                $data['password_source'] = 'unknown';
            }
            Database::update('users', $data, 'id = :id', array('id' => (int) $existing['id']));
            return (int) $existing['id'];
        }
        $data['password_source'] = 'unknown';
        $data['created_at'] = date('Y-m-d H:i:s');
        return Database::insert('users', $data);
    }

    private static function syncAliases(MailuClient $client, $serverId)
    {
        $remote = $client->listAliases();
        $seen = array();
        foreach ($remote as $item) {
            $email = isset($item['email']) ? strtolower(trim((string) $item['email'])) : '';
            if ($email === '') {
                continue;
            }
            $seen[] = $email;
            self::upsertAlias($serverId, $email, $item);
        }
        $removed = self::markMissing('aliases', $serverId, $seen);
        return array('aliases' => count($seen), 'aliases_removed' => $removed);
    }

    private static function upsertAlias($serverId, $email, array $detail)
    {
        $parts = explode('@', $email);
        $domainName = count($parts) > 1 ? $parts[1] : '';
        $domainId = $domainName !== '' ? Database::value('SELECT id FROM {domains} WHERE server_id = ? AND name = ?', array((int) $serverId, $domainName), null) : null;
        $data = array(
            'server_id' => (int) $serverId,
            'domain_id' => $domainId ? (int) $domainId : null,
            'email' => $email,
            'destination' => isset($detail['destination']) ? json_encode((array) $detail['destination'], JSON_UNESCAPED_UNICODE) : null,
            'comment' => isset($detail['comment']) ? $detail['comment'] : '',
            'remote_exists' => 1,
            'synced_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        );
        $existing = Database::fetch('SELECT id FROM {aliases} WHERE server_id = ? AND email = ?', array((int) $serverId, $email));
        if ($existing) {
            Database::update('aliases', $data, 'id = :id', array('id' => (int) $existing['id']));
            return (int) $existing['id'];
        }
        $data['created_at'] = date('Y-m-d H:i:s');
        return Database::insert('aliases', $data);
    }

    /** Flag local rows that no longer exist on the mail server. */
    private static function markMissing($table, $serverId, array $seen, $column = 'email')
    {
        if ($seen) {
            $placeholders = implode(',', array_fill(0, count($seen), '?'));
            $params = array_merge(array((int) $serverId), $seen);
            $removed = Database::run(
                'UPDATE {' . $table . '} SET remote_exists = 0 WHERE server_id = ? AND ' . $column . ' NOT IN (' . $placeholders . ') AND remote_exists = 1',
                $params
            )->rowCount();
            Database::run(
                'UPDATE {' . $table . '} SET remote_exists = 1 WHERE server_id = ? AND ' . $column . ' IN (' . $placeholders . ')',
                $params
            );
            return (int) $removed;
        }
        // nothing on the server: mark everything as removed
        if (in_array($table, array('users', 'aliases'), true)) {
            return (int) Database::run('UPDATE {' . $table . '} SET remote_exists = 0 WHERE server_id = ? AND remote_exists = 1', array((int) $serverId))->rowCount();
        }
        return 0;
    }
}