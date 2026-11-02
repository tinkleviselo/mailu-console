<?php
/**
 * Domain management (multiple domains per Mailu instance, multiple instances).
 */

namespace App\Service;

use App\Core\Database;
use App\Core\Logger;

final class DomainService
{
    public static function paginate(array $filters = array(), $page = 1, $perPage = 100)
    {
        $where = array('1=1');
        $params = array();
        if (!empty($filters['server_id'])) {
            $where[] = 'd.server_id = :server_id';
            $params['server_id'] = (int) $filters['server_id'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(d.name LIKE :q OR d.comment LIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }
        $sql = implode(' AND ', $where);
        $total = (int) Database::value('SELECT COUNT(*) FROM {domains} d WHERE ' . $sql, $params, 0);
        $perPage = max(5, min(500, (int) $perPage));
        $page = max(1, (int) $page);
        $offset = ($page - 1) * $perPage;
        $rows = Database::fetchAll(
            'SELECT d.*, s.name AS server_name FROM {domains} d LEFT JOIN {servers} s ON s.id = d.server_id'
            . ' WHERE ' . $sql . ' ORDER BY d.name ASC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );
        foreach ($rows as $index => $row) {
            $rows[$index]['user_count'] = (int) Database::value('SELECT COUNT(*) FROM {users} WHERE server_id = ? AND domain_name = ?', array((int) $row['server_id'], $row['name']), 0);
            $rows[$index]['alias_count'] = (int) Database::value('SELECT COUNT(*) FROM {aliases} WHERE server_id = ? AND domain_id = ?', array((int) $row['server_id'], (int) $row['id']), 0);
            $rows[$index]['dns'] = json_field($row['dns_json']);
        }
        return array('items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => (int) ceil($total / $perPage));
    }

    public static function all(array $filters = array())
    {
        $result = self::paginate($filters, 1, 2000);
        return $result['items'];
    }

    public static function find($id)
    {
        $row = Database::fetch('SELECT * FROM {domains} WHERE id = ?', array((int) $id));
        if ($row) {
            $row['dns'] = json_field($row['dns_json']);
        }
        return $row;
    }

    public static function findOrFail($id)
    {
        $row = self::find($id);
        if (!$row) {
            throw new \RuntimeException('域名不存在: #' . (int) $id);
        }
        return $row;
    }

    public static function findByName($serverId, $name)
    {
        return Database::fetch('SELECT * FROM {domains} WHERE server_id = ? AND name = ?', array((int) $serverId, strtolower($name)));
    }

    public static function create($serverId, array $input)
    {
        $server = ServerService::findOrFail($serverId);
        $name = strtolower(trim((string) $input['name']));
        if (!preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $name)) {
            throw new \RuntimeException('域名格式不正确: ' . $name);
        }
        $payload = array('name' => $name);
        foreach (array('comment') as $key) {
            if (isset($input[$key]) && $input[$key] !== '') {
                $payload[$key] = (string) $input[$key];
            }
        }
        foreach (array('max_users', 'max_aliases') as $key) {
            if (isset($input[$key]) && $input[$key] !== '' && $input[$key] !== null) {
                $payload[$key] = (int) $input[$key];
            }
        }
        if (isset($input['max_quota_mb']) && $input['max_quota_mb'] !== '' && $input['max_quota_mb'] !== null) {
            $payload['max_quota_bytes'] = (int) round(((float) $input['max_quota_mb']) * 1048576);
        }
        if (array_key_exists('signup_enabled', $input)) {
            $payload['signup_enabled'] = (bool) $input['signup_enabled'];
        }
        if (!empty($input['alternatives'])) {
            $alts = array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) $input['alternatives'])));
            if ($alts) {
                $payload['alternatives'] = array_values($alts);
            }
        }
        $client = ServerService::client($server);
        $client->createDomain($payload);
        $domain = self::mirror($client, $serverId, $name);
        AuditService::log('domain.create', 'ok', $payload, array(
            'server_id' => (int) $serverId, 'target_type' => 'domain', 'target_label' => $name,
        ));
        return $domain;
    }

    public static function mirror(MailuClient $client, $serverId, $name)
    {
        $detail = $client->getDomain($name);
        if (!is_array($detail)) {
            $detail = array('name' => $name);
        }
        $existing = self::findByName($serverId, $name);
        $data = array(
            'server_id' => (int) $serverId,
            'name' => strtolower($name),
            'comment' => isset($detail['comment']) ? (string) $detail['comment'] : '',
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
            return self::find((int) $existing['id']);
        }
        $data['created_at'] = date('Y-m-d H:i:s');
        return self::find(Database::insert('domains', $data));
    }

    public static function update($id, array $input)
    {
        $domain = self::findOrFail($id);
        $server = ServerService::findOrFail($domain['server_id']);
        $payload = array();
        if (isset($input['comment'])) {
            $payload['comment'] = (string) $input['comment'];
        }
        foreach (array('max_users', 'max_aliases') as $key) {
            if (isset($input[$key]) && $input[$key] !== '') {
                $payload[$key] = (int) $input[$key];
            }
        }
        if (isset($input['max_quota_mb']) && $input['max_quota_mb'] !== '') {
            $payload['max_quota_bytes'] = (int) round(((float) $input['max_quota_mb']) * 1048576);
        }
        if (array_key_exists('signup_enabled', $input)) {
            $payload['signup_enabled'] = (bool) $input['signup_enabled'];
        }
        if (!$payload) {
            throw new \RuntimeException('没有需要更新的字段');
        }
        $client = ServerService::client($server);
        $client->updateDomain($domain['name'], $payload);
        $updated = self::mirror($client, (int) $domain['server_id'], $domain['name']);
        AuditService::log('domain.update', 'ok', $payload, array(
            'server_id' => (int) $domain['server_id'], 'target_type' => 'domain', 'target_label' => $domain['name'],
        ));
        return $updated;
    }

    public static function delete($id, $deleteUsersToo = false)
    {
        $domain = self::findOrFail($id);
        $server = ServerService::findOrFail($domain['server_id']);
        $client = ServerService::client($server);
        if ($deleteUsersToo) {
            $users = Database::fetchAll('SELECT * FROM {users} WHERE server_id = ? AND domain_name = ?', array((int) $domain['server_id'], $domain['name']));
            foreach ($users as $user) {
                try {
                    $client->deleteUser($user['email']);
                    Database::delete('users', 'id = :id', array('id' => (int) $user['id']));
                } catch (\Throwable $e) {
                    Logger::warn('cascade user delete failed', array('email' => $user['email'], 'error' => $e->getMessage()));
                }
            }
        }
        $client->deleteDomain($domain['name']);
        Database::delete('aliases', 'server_id = :sid AND domain_id = :did', array('sid' => (int) $domain['server_id'], 'did' => (int) $domain['id']));
        Database::delete('users', 'server_id = :sid AND domain_name = :name', array('sid' => (int) $domain['server_id'], 'name' => $domain['name']));
        Database::delete('domains', 'id = :id', array('id' => (int) $id));
        AuditService::log('domain.delete', 'ok', array('delete_users' => (bool) $deleteUsersToo), array(
            'server_id' => (int) $domain['server_id'], 'target_type' => 'domain', 'target_label' => $domain['name'],
        ));
        return true;
    }

    /** Ask Mailu to generate the DKIM (and DMARC) keys, then cache the DNS records. */
    public static function generateDkim($id)
    {
        $domain = self::findOrFail($id);
        $server = ServerService::findOrFail($domain['server_id']);
        $client = ServerService::client($server);
        $result = $client->generateDkim($domain['name']);
        $updated = self::mirror($client, (int) $domain['server_id'], $domain['name']);
        AuditService::log('domain.dkim', 'ok', is_array($result) ? $result : array(), array(
            'server_id' => (int) $domain['server_id'], 'target_type' => 'domain', 'target_label' => $domain['name'],
        ));
        return $updated;
    }

    public static function sync($id)
    {
        $domain = self::findOrFail($id);
        $server = ServerService::findOrFail($domain['server_id']);
        $client = ServerService::client($server);
        return self::mirror($client, (int) $domain['server_id'], $domain['name']);
    }

    /** DNS records the console should show for a domain (Mailu values first). */
    public static function expectedRecords(array $domain, array $server, array $options = array())
    {
        $dns = isset($domain['dns']) ? (array) $domain['dns'] : json_field(isset($domain['dns_json']) ? $domain['dns_json'] : null);
        $mailHost = parse_url($server['base_url'], PHP_URL_HOST);
        $selector = !empty($server['dkim_selector']) ? $server['dkim_selector'] : 'dkim';
        $records = array();
        $records[] = array('type' => 'MX', 'name' => $domain['name'], 'value' => !empty($dns['mx']) ? $dns['mx'] : $mailHost, 'priority' => 10, 'note' => '收信指向邮件服务器');
        $records[] = array('type' => 'TXT', 'name' => $domain['name'], 'value' => !empty($dns['spf']) ? $dns['spf'] : 'v=spf1 mx ~all', 'note' => 'SPF：允许的发信来源');
        $records[] = array('type' => 'TXT', 'name' => $selector . '._domainkey.' . $domain['name'], 'value' => !empty($dns['dkim']) ? $dns['dkim'] : '(点击“生成 DKIM 密钥”后自动获取)', 'note' => 'DKIM 发信签名公钥');
        $records[] = array('type' => 'TXT', 'name' => '_dmarc.' . $domain['name'], 'value' => !empty($dns['dmarc']) ? $dns['dmarc'] : 'v=DMARC1; p=quarantine; rua=mailto:postmaster@' . $domain['name'], 'note' => 'DMARC 策略与报告');
        if (!empty($dns['dmarc_report'])) {
            $records[] = array('type' => 'TXT', 'name' => '_dmarc.' . $domain['name'], 'value' => $dns['dmarc_report'], 'note' => 'DMARC 报告地址');
        }
        foreach (json_field(isset($dns['autoconfig']) ? $dns['autoconfig'] : null) as $entry) {
            $records[] = array('type' => 'DNS', 'name' => $domain['name'], 'value' => is_array($entry) ? json_encode($entry, JSON_UNESCAPED_UNICODE) : (string) $entry, 'note' => '客户端自动发现');
        }
        if (!empty($options['with_mta_sts'])) {
            $records[] = array('type' => 'TXT', 'name' => '_mta-sts.' . $domain['name'], 'value' => 'v=STSv1; id=' . date('YmdHis'), 'note' => 'MTA-STS（可选）');
            $records[] = array('type' => 'TXT', 'name' => '_smtp._tls.' . $domain['name'], 'value' => 'v=TLSRPTv1; rua=mailto:postmaster@' . $domain['name'], 'note' => 'TLS 报告（可选）');
        }
        return $records;
    }
}