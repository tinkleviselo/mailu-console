<?php

namespace App\Controller;

use App\Core\Request;
use App\Service\AuditService;
use App\Service\ServerService;

final class AuditController extends Controller
{
    public function index()
    {
        $filters = array(
            'server_id' => Request::int('server_id', 0),
            'action' => Request::str('action', ''),
            'target' => Request::str('target', ''),
            'result' => Request::str('result', ''),
        );
        $page = max(1, Request::int('page', 1));
        $result = AuditService::paginate($filters, $page, 60);
        $this->render('audit/index', array(
            'title' => '操作审计',
            'filters' => $filters,
            'result' => $result,
            'servers' => ServerService::all(),
            'pagination' => $this->paginate($result['page'], $result['per_page'], $result['total']),
        ));
    }
}