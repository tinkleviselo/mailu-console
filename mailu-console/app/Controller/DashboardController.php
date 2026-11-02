<?php

namespace App\Controller;

use App\Core\Database;
use App\Service\ServerService;
use App\Service\UserService;

final class DashboardController extends Controller
{
    public function index()
    {
        $stats = ServerService::stats();
        $servers = ServerService::all();
        $recentAudit = Database::fetchAll('SELECT * FROM {audit_logs} ORDER BY id DESC LIMIT 12');
        $recentSync = Database::fetchAll(
            'SELECT r.*, s.name AS server_name FROM {sync_runs} r LEFT JOIN {servers} s ON s.id = r.server_id ORDER BY r.id DESC LIMIT 8'
        );
        $topUsage = UserService::topUsage(8);
        $domains = Database::fetchAll(
            'SELECT d.*, s.name AS server_name, (SELECT COUNT(*) FROM {users} u WHERE u.server_id = d.server_id AND u.domain_name = d.name) AS user_count'
            . ' FROM {domains} d LEFT JOIN {servers} s ON s.id = d.server_id ORDER BY user_count DESC, d.name ASC LIMIT 12'
        );
        $partitions = array();
        $allDomains = Database::fetchAll('SELECT domain_name, COUNT(*) AS total FROM {users} GROUP BY domain_name ORDER BY total DESC LIMIT 10');
        foreach ($allDomains as $row) {
            $partitions[] = array('label' => $row['domain_name'], 'value' => (int) $row['total']);
        }
        $this->render('dashboard/index', array(
            'title' => '总览',
            'stats' => $stats,
            'servers' => $servers,
            'recentAudit' => $recentAudit,
            'recentSync' => $recentSync,
            'topUsage' => $topUsage,
            'domains' => $domains,
            'partitions' => $partitions,
        ));
    }
}