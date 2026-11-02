<?php

namespace App\Controller;

use App\Core\Database;
use App\Core\Flash;
use App\Core\Request;
use App\Service\AuditService;
use App\Service\DnsService;
use App\Service\DomainService;
use App\Service\ServerService;
use App\Service\SyncService;

final class DomainController extends Controller
{
    public function index()
    {
        $filters = array('server_id' => Request::int('server_id', 0), 'q' => Request::str('q', ''));
        $result = DomainService::paginate($filters, max(1, Request::int('page', 1)), 50);
        $this->render('domains/index', array(
            'title' => '域名管理',
            'filters' => $filters,
            'result' => $result,
            'servers' => ServerService::all(),
            'pagination' => $this->paginate($result['page'], $result['per_page'], $result['total']),
        ));
    }

    public function create()
    {
        $this->render('domains/form', array(
            'title' => '添加域名',
            'domain' => array('id' => 0, 'server_id' => Request::int('server_id', 0), 'name' => '', 'comment' => '', 'max_users' => -1, 'max_aliases' => -1, 'max_quota_bytes' => 0, 'signup_enabled' => 0, 'alternatives' => ''),
            'servers' => ServerService::all(),
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
            $domain = DomainService::create($serverId, $this->input());
            return $domain;
        }, '域名已添加', '/domains');
    }

    public function show($params)
    {
        $domain = DomainService::findOrFail((int) $params['id']);
        $server = ServerService::findOrFail($domain['server_id']);
        $verify = null;
        $records = DomainService::expectedRecords($domain, $server);
        if (Request::bool('dns', false)) {
            $verify = DnsService::verify($records);
        }
        $this->render('domains/show', array(
            'title' => '域名：' . $domain['name'],
            'domain' => $domain,
            'server' => $server,
            'records' => $records,
            'verify' => $verify,
            'users' => Database::fetchAll('SELECT * FROM {users} WHERE server_id = ? AND domain_name = ? ORDER BY email LIMIT 500', array((int) $server['id'], $domain['name'])),
            'aliases' => Database::fetchAll('SELECT * FROM {aliases} WHERE server_id = ? AND domain_id = ? ORDER BY email LIMIT 500', array((int) $server['id'], (int) $domain['id'])),
        ));
    }

    public function update($params)
    {
        $id = (int) $params['id'];
        $this->attempt(function () use ($id) {
            DomainService::update($id, $this->input());
            return true;
        }, '域名设置已更新', '/domains/' . $id);
    }

    public function destroy($params)
    {
        $id = (int) $params['id'];
        $this->attempt(function () use ($id) {
            DomainService::delete($id, Request::bool('delete_users', false));
            return true;
        }, '域名已删除', '/domains');
    }

    public function sync($params)
    {
        $id = (int) $params['id'];
        $this->attempt(function () use ($id) {
            DomainService::sync($id);
            return true;
        }, '域名信息已从邮件服务器刷新', '/domains/' . $id);
    }

    public function generateDkim($params)
    {
        $id = (int) $params['id'];
        $this->attempt(function () use ($id) {
            DomainService::generateDkim($id);
            return true;
        }, 'DKIM/DMARC 密钥已生成，请把 TXT 记录添加到 DNS', '/domains/' . $id);
    }

    public function dnsCheck($params)
    {
        $id = (int) $params['id'];
        $this->attempt(function () use ($id) {
            $domain = DomainService::findOrFail($id);
            $server = ServerService::findOrFail($domain['server_id']);
            $records = DomainService::expectedRecords($domain, $server);
            $verify = DnsService::verify($records);
            $message = sprintf('DNS 校验完成：%d/%d 条记录正确', $verify['ok'], $verify['checked']);
            if (Request::wantsJson() || Request::bool('ajax', false)) {
                $this->json(array('ok' => true, 'message' => $message, 'result' => $verify));
            }
            if ($verify['ok'] === $verify['checked']) {
                Flash::success($message);
            } else {
                Flash::warning($message . '，请检查下方标红的记录');
            }
            return $verify;
        }, null, '/domains/' . $id . '?dns=1');
    }
}