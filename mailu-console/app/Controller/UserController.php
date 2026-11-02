<?php

namespace App\Controller;

use App\Core\Config;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Settings;
use App\Core\Validator;
use App\Service\AuditService;
use App\Service\CredentialService;
use App\Service\DomainService;
use App\Service\ImportExportService;
use App\Service\MailProbe;
use App\Service\ServerService;
use App\Service\UserService;

final class UserController extends Controller
{
    public function index()
    {
        $filters = $this->filters();
        $perPage = 50;
        $result = UserService::paginate($filters, max(1, Request::int('page', 1)), $perPage);
        $this->render('users/index', array(
            'title' => '邮箱账号',
            'filters' => $filters,
            'result' => $result,
            'servers' => ServerService::all(),
            'domains' => DomainService::all(array('server_id' => $filters['server_id'])),
            'pagination' => $this->paginate($result['page'], $result['per_page'], $result['total']),
            'quotaDefault' => (int) Settings::get('default.quota_mb', Config::get('mailu.default_quota_mb', 2048)),
        ));
    }

    public function create()
    {
        $servers = ServerService::all(array('enabled' => 1));
        $serverId = Request::int('server_id', $servers ? (int) $servers[0]['id'] : 0);
        $this->render('users/form', array(
            'title' => '新建邮箱账号',
            'user' => array(
                'id' => 0, 'server_id' => $serverId, 'domain_name' => Request::str('domain', ''), 'local_part' => '',
                'displayed_name' => '', 'comment' => '', 'quota_bytes' => 0, 'enabled' => 1, 'global_admin' => 0,
                'enable_imap' => 1, 'enable_pop' => 1, 'forward_enabled' => 0, 'forward_destination' => '',
                'spam_enabled' => 1, 'spam_threshold' => 80,
            ),
            'servers' => $servers,
            'domains' => DomainService::all(array('server_id' => $serverId)),
            'isEdit' => false,
            'defaultQuotaMb' => (int) Settings::get('default.quota_mb', Config::get('mailu.default_quota_mb', 2048)),
        ));
    }

    public function store()
    {
        $this->attempt(function () {
            $input = $this->input();
            $serverId = Request::int('server_id', 0);
            if ($serverId <= 0) {
                throw new \RuntimeException('请选择所属邮件服务器');
            }
            $email = Request::str('email');
            if ($email === '') {
                $email = Request::str('local_part') . '@' . Request::str('domain_name');
            }
            $payload = array(
                'email' => $email,
                'password' => (string) Request::input('password', ''),
                'quota_mb' => Request::has('quota_mb') ? Request::str('quota_mb') : '',
                'comment' => Request::str('comment'),
                'displayed_name' => Request::str('displayed_name'),
                'enabled' => Request::bool('enabled', true),
                'global_admin' => Request::bool('global_admin', false),
                'enable_imap' => Request::bool('enable_imap', true),
                'enable_pop' => Request::bool('enable_pop', true),
                'forward_enabled' => Request::bool('forward_enabled', false),
                'forward_destination' => Request::str('forward_destination'),
                'spam_enabled' => Request::bool('spam_enabled', true),
                'spam_threshold' => Request::has('spam_threshold') ? Request::str('spam_threshold') : '',
            );
            if ($payload['password'] === '') {
                $length = (int) Settings::get('default.password_length', Config::get('mailu.default_password_length', 16));
                $payload['password'] = random_password($length);
            }
            $result = UserService::create($serverId, $payload);
            Flash::success('账号 ' . $email . ' 创建成功');
            Flash::add('info', '初始密码：' . $result['password'] . '（请复制保存，稍后可在账号详情页再次查看）');
            return $result;
        }, null, '/users');
    }

    public function show($params)
    {
        $user = UserService::findOrFail((int) $params['id']);
        $server = ServerService::findOrFail($user['server_id']);
        $card = CredentialService::forUser($user, $server);
        $probe = null;
        if (Request::bool('probe', false) && Settings::bool('probe.enabled', true)) {
            if ($card['password'] === '') {
                Flash::warning('该账号没有保存密码，无法直接做登录测试；请先重置密码或手动填写密码测试。');
            } else {
                $tests = array();
                foreach (array('imap', 'smtp', 'pop3') as $test) {
                    if (Request::bool('test_' . $test, $test !== 'pop3')) {
                        $tests[] = $test;
                    }
                }
                $probe = MailProbe::run($server, $user['email'], $card['password'], $tests);
                AuditService::log('user.probe', 'ok', array('tests' => $tests), array(
                    'server_id' => (int) $server['id'], 'target_type' => 'user', 'target_label' => $user['email'],
                ));
            }
        }
        $this->render('users/show', array(
            'title' => '账号：' . $user['email'],
            'user' => $user,
            'server' => $server,
            'card' => $card,
            'probe' => $probe,
            'credentials' => $server,
            'audit' => \App\Core\Database::fetchAll('SELECT * FROM {audit_logs} WHERE target_label = ? ORDER BY id DESC LIMIT 15', array($user['email'])),
        ));
    }

    public function update($params)
    {
        $id = (int) $params['id'];
        $this->attempt(function () use ($id) {
            $payload = array(
                'quota_mb' => Request::has('quota_mb') ? Request::str('quota_mb') : '',
                'comment' => Request::str('comment'),
                'displayed_name' => Request::str('displayed_name'),
                'enabled' => Request::bool('enabled', true),
                'global_admin' => Request::bool('global_admin', false),
                'enable_imap' => Request::bool('enable_imap', true),
                'enable_pop' => Request::bool('enable_pop', true),
                'forward_enabled' => Request::bool('forward_enabled', false),
                'forward_destination' => Request::str('forward_destination'),
                'spam_enabled' => Request::bool('spam_enabled', true),
                'spam_threshold' => Request::has('spam_threshold') ? Request::str('spam_threshold') : '',
            );
            $newPassword = (string) Request::input('password', '');
            if ($newPassword !== '') {
                $payload['password'] = $newPassword;
            }
            UserService::update($id, $payload);
            return true;
        }, '账号已更新', '/users/' . $id);
    }

    public function destroy($params)
    {
        $id = (int) $params['id'];
        $this->attempt(function () use ($id) {
            UserService::delete($id);
            return true;
        }, '账号已删除', '/users');
    }

    public function toggle($params)
    {
        $id = (int) $params['id'];
        try {
            $enabled = UserService::toggle($id);
            if (Request::wantsJson() || Request::bool('ajax', false)) {
                $this->json(array('ok' => true, 'enabled' => $enabled));
            }
            Flash::success($enabled ? '账号已启用' : '账号已禁用');
        } catch (\Throwable $e) {
            if (Request::wantsJson() || Request::bool('ajax', false)) {
                $this->json(array('ok' => false, 'error' => $e->getMessage()), 400);
            }
            Flash::error($e->getMessage());
        }
        $this->redirect($this->referer());
    }

    public function resetPassword($params)
    {
        $id = (int) $params['id'];
        $this->attempt(function () use ($id) {
            $password = (string) Request::input('new_password', '');
            if ($password === '') {
                $length = (int) Settings::get('default.password_length', Config::get('mailu.default_password_length', 16));
                $password = random_password($length);
            }
            $user = UserService::findOrFail($id);
            UserService::setPassword($id, $password, 'console');
            Flash::add('info', '新密码（' . $user['email'] . '）：' . $password);
            return $password;
        }, '密码已重置', '/users/' . $id);
    }

    public function sync($params)
    {
        $id = (int) $params['id'];
        $this->attempt(function () use ($id) {
            $user = UserService::findOrFail($id);
            $server = ServerService::findOrFail($user['server_id']);
            $client = ServerService::client($server);
            UserService::mirrorFromRemote($client, (int) $server['id'], $user['email']);
            return true;
        }, '账号信息已刷新', '/users/' . $id);
    }

    public function credentials($params)
    {
        $user = UserService::findOrFail((int) $params['id']);
        $server = ServerService::findOrFail($user['server_id']);
        $card = CredentialService::forUser($user, $server);
        $format = Request::str('format', 'html');
        $reveal = !Request::bool('hide', false);
        if (!$reveal) {
            $card['password'] = $card['password'] === '' ? '' : str_repeat('*', max(8, strlen($card['password'])));
        }
        if ($format === 'txt') {
            \App\Core\Response::download($user['email'] . '-登录信息.txt', CredentialService::asText($card), 'text/plain; charset=utf-8');
        }
        if ($format === 'csv') {
            \App\Core\Response::download($user['email'] . '-登录信息.csv', CredentialService::asCsv(array($card)), 'text/csv; charset=utf-8');
        }
        if ($format === 'xml') {
            \App\Core\Response::download($user['domain_name'] . '-autoconfig.xml', CredentialService::autoconfigXml($user['domain_name'], $server), 'application/xml; charset=utf-8');
        }
        $this->render('users/credentials', array(
            'title' => '登录信息：' . $user['email'],
            'user' => $user,
            'server' => $server,
            'card' => $card,
        ), 'print_layout');
    }

    public function export()
    {
        $filters = $this->filters();
        $rows = UserService::exportRows($filters);
        $csv = ImportExportService::csv($rows);
        AuditService::log('user.export', 'ok', array('rows' => count($rows)));
        \App\Core\Response::download('mailu-accounts-' . date('Ymd-His') . '.csv', $csv, 'text/csv; charset=utf-8');
    }

    public function bulk()
    {
        $servers = ServerService::all(array('enabled' => 1));
        $serverId = Request::int('server_id', $servers ? (int) $servers[0]['id'] : 0);
        $this->render('users/bulk', array(
            'title' => '批量创建账号',
            'servers' => $servers,
            'domains' => DomainService::all(array('server_id' => $serverId)),
            'serverId' => $serverId,
            'defaultQuotaMb' => (int) Settings::get('default.quota_mb', Config::get('mailu.default_quota_mb', 2048)),
            'result' => null,
        ));
    }

    public function bulkStore()
    {
        $serverId = Request::int('server_id', 0);
        $text = (string) Request::input('accounts', '');
        if ($serverId <= 0) {
            $this->fail('请选择所属邮件服务器', '/users/bulk');
        }
        if (trim($text) === '') {
            $this->fail('请粘贴需要创建的账号列表', '/users/bulk');
        }
        try {
            $result = UserService::bulk($serverId, $text, array(
                'domain' => Request::str('domain_name', ''),
                'quota_mb' => Request::str('quota_mb', ''),
            ));
            $servers = ServerService::all(array('enabled' => 1));
            $this->render('users/bulk', array(
                'title' => '批量创建账号',
                'servers' => $servers,
                'domains' => DomainService::all(array('server_id' => $serverId)),
                'serverId' => $serverId,
                'defaultQuotaMb' => (int) Settings::get('default.quota_mb', 2048),
                'result' => $result,
            ));
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), '/users/bulk');
        }
    }
}