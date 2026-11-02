<?php
/**
 * Aliases / forwarders / distribution lists per domain.
 */

namespace App\Service;

use App\Core\Database;

final class AliasService
{
    public static function paginate(array $filters = array(), $page = 1, $perPage = 100)
    {
        $where = array('1=1');
        $params = array();
        if (!empty($filters['server_id'])) {
            $where[] = 'a.server_id = :server_id';
            $params['server_id'] = (int) $filters['server_id'];
        }
        if (!empty($filters['domain'])) {
            $params['domain'] = strtolower($filters['domain']);
            $where[] = 'a.email LIKE :domain_like';
            $params['domain_like'] = '%@' . strtolower($filters['domain']);
        }
        if (!empty($filters['q'])) {
            $where[] = '(a.email LIKE :q OR a.destination LIKE :q OR a.comment LIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }
        $sql = implode(' AND ', $where);
        $total = (int) Database::value('SELECT COUNT(*) FROM {aliases} a WHERE ' . $sql, $params, 0);
        $perPage = max(5, min(500, (int) $perPage));
        $page = max(1, (int) $page);
        $offset = ($page - 1) * $perPage;
        $rows = Database::fetchAll(
            'SELECT a.*, s.name AS server_name, d.name AS domain_name FROM {aliases} a'
            . ' LEFT JOIN {servers} s ON s.id = a.server_id LEFT JOIN {domains} d ON d.id = a.domain_id'
            . ' WHERE ' . $sql . ' ORDER BY a.email ASC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );
        foreach ($rows as $index => $row) {
            $rows[$index]['destinations'] = json_field($row['destination']);
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
        $row = Database::fetch('SELECT * FROM {aliases} WHERE id = ?', array((int) $id));
        if ($row) {
            $row['destinations'] = json_field($row['destination']);
        }
        return $row;
    }

    public static function findOrFail($id)
    {
        $row = self::find($id);
        if (!$row) {
            throw new \RuntimeException('别名不存在: #' . (int) $id);
        }
        return $row;
    }

    public static function create($serverId, array $input)
    {
        $server = ServerService::findOrFail($serverId);
        $email = strtolower(trim((string) $input['email']));
        if ($email === '' && !empty($input['local_part']) && !empty($input['domain_name'])) {
            $email = strtolower(trim($input['local_part']) . '@' . trim($input['domain_name']));
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('别名地址格式不正确');
        }
        $destinations = UserService::destinationList(isset($input['destination']) ? $input['destination'] : '');
        if (!$destinations) {
            throw new \RuntimeException('至少需要一个目标地址');
        }
        $payload = array('email' => $email, 'destination' => $destinations);
        if (!empty($input['comment'])) {
            $payload['comment'] = (string) $input['comment'];
        }
        $client = ServerService::client($server);
        $client->createAlias($payload);
        $alias = self::mirror($client, $serverId, $email);
        AuditService::log('alias.create', 'ok', $payload, array(
            'server_id' => (int) $serverId, 'target_type' => 'alias', 'target_label' => $email,
        ));
        return $alias;
    }

    public static function mirror(MailuClient $client, $serverId, $email)
    {
        $detail = $client->getAlias($email);
        if (!is_array($detail)) {
            $detail = array('email' => $email);
        }
        $domainName = explode('@', strtolower($email));
        $domainName = isset($domainName[1]) ? $domainName[1] : '';
        $domainId = $domainName !== '' ? Database::value('SELECT id FROM {domains} WHERE server_id = ? AND name = ?', array((int) $serverId, $domainName), null) : null;
        $data = array(
            'server_id' => (int) $serverId,
            'domain_id' => $domainId ? (int) $domainId : null,
            'email' => strtolower($email),
            'destination' => isset($detail['destination']) ? json_encode((array) $detail['destination'], JSON_UNESCAPED_UNICODE) : null,
            'comment' => isset($detail['comment']) ? (string) $detail['comment'] : '',
            'remote_exists' => 1,
            'synced_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        );
        $existing = Database::fetch('SELECT id FROM {aliases} WHERE server_id = ? AND email = ?', array((int) $serverId, strtolower($email)));
        if ($existing) {
            Database::update('aliases', $data, 'id = :id', array('id' => (int) $existing['id']));
            return self::find((int) $existing['id']);
        }
        $data['created_at'] = date('Y-m-d H:i:s');
        return self::find(Database::insert('aliases', $data));
    }

    public static function update($id, array $input)
    {
        $alias = self::findOrFail($id);
        $server = ServerService::findOrFail($alias['server_id']);
        $payload = array();
        if (isset($input['destination'])) {
            $destinations = UserService::destinationList($input['destination']);
            if (!$destinations) {
                throw new \RuntimeException('至少需要一个目标地址');
            }
            $payload['destination'] = $destinations;
        }
        if (isset($input['comment'])) {
            $payload['comment'] = (string) $input['comment'];
        }
        if (!$payload) {
            throw new \RuntimeException('没有需要更新的字段');
        }
        $client = ServerService::client($server);
        $client->updateAlias($alias['email'], $payload);
        $updated = self::mirror($client, (int) $alias['server_id'], $alias['email']);
        AuditService::log('alias.update', 'ok', $payload, array(
            'server_id' => (int) $alias['server_id'], 'target_type' => 'alias', 'target_label' => $alias['email'],
        ));
        return $updated;
    }

    public static function delete($id)
    {
        $alias = self::findOrFail($id);
        $server = ServerService::findOrFail($alias['server_id']);
        $client = ServerService::client($server);
        $client->deleteAlias($alias['email']);
        Database::delete('aliases', 'id = :id', array('id' => (int) $id));
        AuditService::log('alias.delete', 'ok', array(), array(
            'server_id' => (int) $alias['server_id'], 'target_type' => 'alias', 'target_label' => $alias['email'],
        ));
        return true;
    }
}