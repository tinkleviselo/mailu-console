<?php

namespace App\Controller;

use App\Service\AliasService;
use App\Service\DomainService;
use App\Service\ServerService;
use App\Service\SyncService;
use App\Core\Request;

final class AliasController extends Controller
{
    public function index()
    {
        $filters = array(
            'server_id' => Request::int('server_id', 0),
            'domain' => Request::str('domain', ''),
            'q' => Request::str('q', ''),
        );
        $result = AliasService::paginate($filters, max(1, Request::int('page', 1)), 50);
        $this->render('aliases/index', array(
            'title' => '别名 / 转发',
            'filters' => $filters,
            'result' => $result,
            'servers' => ServerService::all(),
            'domains' => DomainService::all(array('server_id' => $filters['server_id'])),
            'pagination' => $this->paginate($result['page'], $result['per_page'], $result['total']),
        ));
    }

    public function create()
    {
        $servers = ServerService::all(array('enabled' => 1));
        $serverId = Request::int('server_id', $servers ? (int) $servers[0]['id'] : 0);
        $this->render('aliases/form', array(
            'title' => '新建别名',
            'alias' => array('id' => 0, 'server_id' => $serverId, 'email' => '', 'destination' => '', 'comment' => ''),
            'servers' => $servers,
            'domains' => DomainService::all(array('server_id' => $serverId)),
            'isEdit' => false,
        ));
    }

    public function store()
    {
        $this->attempt(function () {
            $serverId = Request::int('server_id', 0);
            if ($serverId <= 0) {
                throw new \RuntimeException('请选择所属邮件服务器');
            }
            AliasService::create($serverId, $this->input());
            return true;
        }, '别名已创建', '/aliases');
    }

    public function update($params)
    {
        $id = (int) $params['id'];
        $this->attempt(function () use ($id) {
            AliasService::update($id, $this->input());
            return true;
        }, '别名已更新', '/aliases');
    }

    public function destroy($params)
    {
        $id = (int) $params['id'];
        $this->attempt(function () use ($id) {
            AliasService::delete($id);
            return true;
        }, '别名已删除', '/aliases');
    }

    public function sync()
    {
        $this->attempt(function () {
            $summary = array();
            foreach (ServerService::all() as $server) {
                $summary[] = SyncService::syncServer((int) $server['id'], 'aliases');
            }
            return $summary;
        }, '别名已同步', '/aliases');
    }
}