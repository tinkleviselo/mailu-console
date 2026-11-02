<?php

namespace App\Controller;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Request;
use App\Core\Settings;
use App\Core\Validator;
use App\Service\AuditService;
use App\Service\RemoteDeploy;
use App\Service\ServerService;

final class SettingController extends Controller
{
    public function index()
    {
        $this->render('settings/index', array(
            'title' => '系统设置',
            'settings' => Settings::all(),
            'config' => Config::all(),
            'apiToken' => Settings::get('api.bearer_token', ''),
            'remote' => RemoteDeploy::status(),
            'stats' => ServerService::stats(),
            'phpVersion' => PHP_VERSION,
            'logs' => \App\Core\Logger::tail(80),
        ));
    }

    public function save()
    {
        $this->attempt(function () {
            Settings::set('default.quota_mb', (string) Request::int('default_quota_mb', 2048));
            Settings::set('default.password_length', (string) max(8, Request::int('default_password_length', 16)));
            Settings::set('security.require_strong_password', Request::bool('require_strong_password', true) ? '1' : '0');
            Settings::set('probe.enabled', Request::bool('probe_enabled', true) ? '1' : '0');
            Settings::set('sync.auto_on_dashboard', Request::bool('sync_auto', false) ? '1' : '0');
            Settings::set('app.console_url', Request::str('console_url', ''));
            Settings::set('dns.auto_check', Request::bool('dns_auto_check', true) ? '1' : '0');
            AuditService::log('settings.save', 'ok', array());
            return true;
        }, '设置已保存', '/settings');
    }

    public function password()
    {
        $this->attempt(function () {
            $validator = Validator::make($this->input(), array(
                'current_password' => 'required',
                'new_password' => 'required|min:10',
                'new_password_confirm' => 'required|same:new_password',
            ), array(
                'current_password' => '当前密码',
                'new_password' => '新密码',
                'new_password_confirm' => '确认新密码',
            ));
            if ($validator->fails()) {
                $errors = $validator->errors();
                throw new \RuntimeException(reset($errors));
            }
            $admin = Auth::user();
            if (!$admin || !password_verify((string) Request::input('current_password', ''), (string) $admin['password_hash'])) {
                throw new \RuntimeException('当前密码不正确');
            }
            Auth::updatePassword((int) $admin['id'], (string) Request::input('new_password', ''));
            AuditService::log('admin.password', 'ok', array('username' => $admin['username']));
            return true;
        }, '管理员密码已更新', '/settings');
    }

    public function rotateToken()
    {
        $this->attempt(function () {
            $token = random_token(24);
            Settings::set('api.bearer_token', $token);
            Settings::set('api.bearer_hash', hash('sha256', $token));
            AuditService::log('api.token_rotate', 'ok');
            \App\Core\Flash::add('info', '新的中台 API 令牌: ' . $token);
            return $token;
        }, 'API 令牌已重置', '/settings');
    }
}