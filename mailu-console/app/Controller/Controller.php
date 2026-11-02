<?php

namespace App\Controller;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

abstract class Controller
{
    protected function render($view, array $data = array(), $layout = 'layout')
    {
        $renderer = new View();
        echo $renderer->render($view, $data, $layout);
    }

    protected function view($view, array $data = array(), $layout = 'layout')
    {
        $renderer = new View();
        return $renderer->render($view, $data, $layout);
    }

    protected function redirect($path)
    {
        Flash::clearInput();
        Response::redirect($path);
    }

    protected function back($fallback = '/')
    {
        Flash::clearInput();
        Response::back($fallback);
    }

    protected function json($data, $status = 200)
    {
        Response::json($data, $status);
    }

    protected function input()
    {
        return Request::all();
    }

    protected function ok($message, $to = null)
    {
        Flash::success($message);
        $this->redirect($to === null ? $this->referer() : $to);
    }

    protected function fail($message, $to = null)
    {
        Flash::error($message);
        $this->redirect($to === null ? $this->referer() : $to);
    }

    protected function referer()
    {
        return isset($_SERVER['HTTP_REFERER']) && $_SERVER['HTTP_REFERER'] !== '' ? $_SERVER['HTTP_REFERER'] : '/';
    }

    /**
     * Runs a state changing action and converts exceptions into flash errors.
     * Returns whatever the callable returns.
     */
    protected function attempt(callable $fn, $successMessage = null, $redirectTo = null)
    {
        try {
            $result = $fn();
            if ($successMessage !== null) {
                Flash::success($successMessage);
            }
            if (!Request::wantsJson()) {
                $this->redirect($redirectTo === null ? $this->referer() : $redirectTo);
            }
            return $result;
        } catch (\Throwable $e) {
            Logger::error('action failed', array('path' => Request::path(), 'error' => $e->getMessage()));
            if (Request::wantsJson()) {
                Response::json(array('ok' => false, 'error' => $e->getMessage()), 400);
            }
            Flash::error($e->getMessage());
            $this->redirect($redirectTo === null ? $this->referer() : $redirectTo);
        }
    }

    protected function isPost()
    {
        return Request::isPost();
    }

    protected function adminName()
    {
        return Auth::name();
    }

    protected function paginate($page, $perPage, $total)
    {
        return array(
            'page' => max(1, (int) $page),
            'per_page' => max(1, (int) $perPage),
            'total' => (int) $total,
            'pages' => (int) ceil(((int) $total) / max(1, (int) $perPage)),
        );
    }

    protected function filters()
    {
        return array(
            'server_id' => Request::int('server_id', 0),
            'domain' => Request::str('domain', ''),
            'q' => Request::str('q', ''),
            'status' => Request::has('status') ? Request::str('status') : '',
            'password_known' => Request::bool('password_known', false),
        );
    }
}