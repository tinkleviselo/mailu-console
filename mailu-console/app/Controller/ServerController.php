<?php

namespace App\Controller;

use App\Core\Database;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Validator;
use App\Service\AuditService;
use App\Service\CredentialService;
use App\Service\ServerService;
use App\Service\SyncService;

final class ServerController extends Controller
{
    public function index()
    {
        $this->render('servers/index', array(
            'title' => '邮件服务器',
            'servers' => ServerService::all(),
            'stats' => ServerService::stats(),
        ));
    }

    public function create()
    {
        $this->render('servers/form', array(
            'title' => '添加邮件服务器',
            'server' => ServerService::defaults(),
            'isEdit' => false,
            'servers' => array(),
        ));
    }

    public function store()
    {
        $this->attempt(function () {
            $input = $this->input();
            $validator = Validator::make($input, array(
                'name' => 'required|max:64',
                'base_url' => 'required|url',
                'api_path' => 'max:32',
            ), array('name' => '名称', 'base_url' => '访问地址', 'api_path' => 'API 路径'));
            if ($validator->fails()) {
                $errors = $validator->errors();
                Flash::withInput($input, $errors);
                throw new \RuntimeException(reset($errors));
            }
            $data = $this->collect($input, true);
            $data = ServerService::deriveMailDefaults($data);
            $id = ServerService::create($data);
            try {
                SyncService::syncServer($id, 'all');
            } catch (\Throwable $e) {
                Flash::warning('服务器已保存，但首次同步失败: ' . $e->getMessage());
            }
            return $id;
        }, '邮件服务器已添加', '/servers');
    }

    public function show($params)
    {
        $server = ServerService::findOrFail((int) $params['id']);
        $check = null;
        if (Request::bool('check', false)) {
            try {
                $check = ServerService::check((int) $server['id']);
                $server = ServerService::findOrFail((int) $server['id']);
            } catch (\Throwable $e) {
                Flash::error('检查失败: ' . $e->getMessage());
            }
        }
        $this->render('servers/show', array(
            'title' => '服务器：' . $server['name'],
            'server' => $server,
            'check' => $check,
            'domains' => Database::fetchAll('SELECT * FROM {domains} WHERE server_id = ? ORDER BY name', array((int) $server['id'])),
            'endpoints' => CredentialService::endpoints($server),
            'audit' => Database::fetchAll('SELECT * FROM {audit_logs} WHERE server_id = ? ORDER BY id DESC LIMIT 15', array((int) $server['id'])),
            'syncRuns' => Database::fetchAll('SELECT * FROM {sync_runs} WHERE server_id = ? ORDER BY id DESC LIMIT 6', array((int) $server['id'])),
            'stats' => array(
                'users' => (int) Database::value('SELECT COUNT(*) FROM {users} WHERE server_id = ?', array((int) $server['id']), 0),
                'aliases' => (int) Database::value('SELECT COUNT(*) FROM {aliases} WHERE server_id = ?', array((int) $server['id']), 0),
                'quota' => (int) Database::value('SELECT COALESCE(SUM(quota_bytes),0) FROM {users} WHERE server_id = ?', array((int) $server['id']), 0),
                'used' => (int) Database::value('SELECT COALESCE(SUM(quota_bytes_used),0) FROM {users} WHERE server_id = ?', array((int) $server['id']), 0),
            ),
        ));
    }

    public function edit($params)
    {
        $server = ServerService::findOrFail((int) $params['id']);
        $server['api_token'] = '';
        $server['api_user'] = '';
        $server['api_pass'] = '';
        $this->render('servers/form', array(
            'title' => '编辑服务器：' . $server['name'],
            'server' => $server,
            'isEdit' => true,
        ));
    }

    public function update($params)
    {
        $id = (int) $params['id'];
        $this->attempt(function () use ($id) {
            $input = $this->input();
            $validator = Validator::make($input, array(
                'name' => 'required|max:64',
                'base_url' => 'required|url',
            ), array('name' => '名称', 'base_url' => '访问地址'));
            if ($validator->fails()) {
                $errors = $validator->errors();
                Flash::withInput($input, $errors);
                throw new \RuntimeException(reset($errors));
            }
            ServerService::update($id, $this->collect($input, false));
            return true;
        }, '服务器已更新', '/servers/' . $id);
    }

    public function destroy($params)
    {
        $id = (int) $params['id'];
        $this->attempt(function () use ($id) {
            ServerService::delete($id, true);
            return true;
        }, '服务器已从列表中删除（邮件系统本身未受影响）', '/servers');
    }

    public function check($params)
    {
        $id = (int) $params['id'];
        try {
            $result = ServerService::check($id);
            if (Request::wantsJson() || Request::bool('ajax', false)) {
                $this->json(array('ok' => $result['ok'], 'message' => $result['message'], 'api' => $result['api'], 'cert' => $result['cert']));
            }
            if ($result['ok']) {
                Flash::success('连接正常：' . $result['message']);
            } else {
                Flash::error('连接失败：' . $result['message']);
            }
        } catch (\Throwable $e) {
            if (Request::wantsJson() || Request::bool('ajax', false)) {
                $this->json(array('ok' => false, 'message' => $e->getMessage()), 400);
            }
            Flash::error($e->getMessage());
        }
        $this->redirect('/servers/' . $id);
    }

    public function sync($params)
    {
        $id = (int) $params['id'];
        try {
            $result = SyncService::syncServer($id, Request::str('scope', 'all'));
            $message = sprintf('同步完成：域名 %d，账号 %d，别名 %d', $result['stats']['domains'], $result['stats']['users'], $result['stats']['aliases']);
            if ($result['status'] !== 'ok') {
                $message = '同步失败: ' . $result['message'];
            }
            if (Request::wantsJson() || Request::bool('ajax', false)) {
                $this->json(array('ok' => $result['status'] === 'ok', 'message' => $message, 'stats' => $result['stats']));
            }
            $result['status'] === 'ok' ? Flash::success($message) : Flash::error($message);
        } catch (\Throwable $e) {
            if (Request::wantsJson() || Request::bool('ajax', false)) {
                $this->json(array('ok' => false, 'message' => $e->getMessage()), 400);
            }
            Flash::error($e->getMessage());
        }
        $this->redirect('/servers/' . $id);
    }

    public function syncAll()
    {
        $this->attempt(function () {
            $summary = SyncService::syncAll();
            $ok = 0;
            foreach ($summary as $item) {
                if ($item['status'] === 'ok') {
                    $ok++;
                }
            }
            return array('ok' => $ok, 'total' => count($summary));
        }, '已同步全部服务器', '/servers');
    }

    private function collect(array $input, $isCreate)
    {
        $data = ServerService::defaults();
        foreach (array('name', 'base_url', 'admin_url', 'webmail_url', 'api_path', 'auth_mode', 'note', 'dkim_selector', 'imap_host', 'imap_security', 'pop3_host', 'pop3_security', 'smtp_host', 'smtp_security', 'sieve_host') as $key) {
            if (array_key_exists($key, $input)) {
                $data[$key] = trim((string) $input[$key]);
            }
        }
        foreach (array('imap_port', 'pop3_port', 'smtp_port', 'sieve_port', 'timeout') as $key) {
            if (isset($input[$key]) && $input[$key] !== '') {
                $data[$key] = (int) $input[$key];
            }
        }
        $data['api_token'] = (string) (isset($input['api_token']) ? $input['api_token'] : '');
        $data['api_user'] = (string) (isset($input['api_user']) ? $input['api_user'] : '');
        $data['api_pass'] = (string) (isset($input['api_pass']) ? $input['api_pass'] : '');
        $data['verify_tls'] = Request::bool('verify_tls', true) ? 1 : 0;
        $data['enabled'] = Request::bool('enabled', true) ? 1 : 0;
        if ($data['api_path'] === '') {
            $data['api_path'] = '/api/v1';
        }
        if ($data['base_url'] !== '' && strpos($data['base_url'], 'http') !== 0) {
            $data['base_url'] = 'https://' . $data['base_url'];
        }
        if ($data['auth_mode'] === '' || !in_array($data['auth_mode'], array('bearer', 'basic'), true)) {
            $data['auth_mode'] = $data['api_pass'] !== '' ? 'basic' : 'bearer';
        }
        if ($isCreate && $data['api_token'] === '' && $data['api_user'] === '') {
            auditLogNote('未填写 API 令牌或管理员账号，添加后需要补充');
        }
        return $data;
    }
}

if (!function_exists('auditLogNote')) {
    function auditLogNote($message)
    {
        AuditService::log('server.note', 'warning', array('message' => $message));
    }
}