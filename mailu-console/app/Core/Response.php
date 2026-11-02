<?php
/**
 * Response helpers.
 */

namespace App\Core;

final class Response
{
    public static function redirect($to, $status = 302)
    {
        $url = (strpos($to, 'http://') === 0 || strpos($to, 'https://') === 0) ? $to : url($to);
        if (!headers_sent()) {
            header('Location: ' . $url, true, $status);
        }
        exit;
    }

    public static function back($fallback = '/')
    {
        $ref = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
        self::redirect($ref !== '' ? $ref : $fallback);
    }

    public static function json($data, $status = 200)
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    public static function jsonError($message, $status = 400, array $extra = array())
    {
        self::json(array_merge(array('ok' => false, 'error' => $message), $extra), $status);
    }

    public static function download($filename, $content, $mime = 'application/octet-stream')
    {
        $safe = preg_replace('/[^A-Za-z0-9._\-]/', '_', (string) $filename);
        if (!headers_sent()) {
            header('Content-Type: ' . $mime);
            header('Content-Disposition: attachment; filename="' . $safe . '"');
            header('Content-Length: ' . strlen($content));
            header('X-Content-Type-Options: nosniff');
        }
        echo $content;
        exit;
    }

    public static function text($content, $status = 200)
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo $content;
        exit;
    }

    public static function abort($status, $message = '')
    {
        http_response_code($status);
        if (Request::wantsJson()) {
            self::json(array('ok' => false, 'error' => $message ?: 'HTTP ' . $status), $status);
        }
        $view = new View();
        echo $view->render('errors/error', array('status' => $status, 'message' => $message));
        exit;
    }
}