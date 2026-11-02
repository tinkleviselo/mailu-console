<?php

namespace App\Controller;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Service\AuditService;

final class AuthController extends Controller
{
    public function showLogin()
    {
        $this->render('auth/login', array('title' => '登录邮件中台'), 'auth_layout');
    }

    public function login()
    {
        $username = Request::str('username');
        $password = (string) Request::input('password', '');
        if ($username === '' || $password === '') {
            Flash::warning('请输入用户名和密码');
            $this->redirect('/login');
        }
        if (Auth::isLocked($username)) {
            AuditService::log('console.login', 'locked', array('username' => $username));
            Flash::error('失败次数过多，请 15 分钟后再试');
            $this->redirect('/login');
        }
        if (!Auth::attempt($username, $password)) {
            AuditService::log('console.login', 'failed', array('username' => $username));
            Flash::error('用户名或密码不正确');
            $this->redirect('/login');
        }
        AuditService::log('console.login', 'ok', array('username' => $username));
        $intended = (string) Session::get('__intended', '/');
        Session::forget('__intended');
        $this->redirect($intended !== '' ? $intended : '/');
    }

    public function logout()
    {
        AuditService::log('console.logout', 'ok');
        Auth::logout();
        $this->redirect('/login');
    }
}