<?php
/**
 * Audit trail: every console action that touches a mail server is recorded.
 */

namespace App\Service;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Request;

final class AuditService
{
    public static function log($action, $result = 'ok', array $meta = array(), $options = array())
    {
        $options = (array) $options;
        try {
            Database::insert('audit_logs', array(
                'admin_id' => Auth::id(),
                'admin_name' => Auth::check() ? Auth::name() : (isset($GLOBALS['__api_identity']) ? (string) $GLOBALS['__api_identity'] : 'system'),
                'server_id' => isset($options['server_id']) ? (int) $options['server_id'] : null,
                'action' => (string) $action,
                'target_type' => isset($options['target_type']) ? (string) $options['target_type'] : '',
                'target_label' => isset($options['target_label']) ? (string) $options['target_label'] : '',
                'result' => (string) $result,
                'message' => isset($options['message']) ? (string) $options['message'] : '',
                'meta_json' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'ip' => Request::ip(),
                'created_at' => date('Y-m-d H:i:s'),
            ));
        } catch (\Throwable $e) {
            Logger::error('audit insert failed', array('error' => $e->getMessage(), 'action' => $action));
        }
    }

    public static function paginate(array $filters = array(), $page = 1, $perPage = 50)
    {
        $where = array('1=1');
        $params = array();
        if (!empty($filters['server_id'])) {
            $where[] = 'server_id = :server_id';
            $params['server_id'] = (int) $filters['server_id'];
        }
        if (!empty($filters['action'])) {
            $where[] = 'action LIKE :action';
            $params['action'] = '%' . $filters['action'] . '%';
        }
        if (!empty($filters['target'])) {
            $where[] = '(target_label LIKE :target OR message LIKE :target)';
            $params['target'] = '%' . $filters['target'] . '%';
        }
        if (!empty($filters['result'])) {
            $where[] = 'result = :result';
            $params['result'] = $filters['result'];
        }
        $sql = implode(' AND ', $where);
        $total = (int) Database::value('SELECT COUNT(*) FROM {audit_logs} WHERE ' . $sql, $params, 0);
        $perPage = max(10, min(200, (int) $perPage));
        $page = max(1, (int) $page);
        $offset = ($page - 1) * $perPage;
        $rows = Database::fetchAll(
            'SELECT * FROM {audit_logs} WHERE ' . $sql . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );
        return array('items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => (int) ceil($total / $perPage));
    }
}