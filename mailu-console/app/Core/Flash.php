<?php
/**
 * One-shot flash messages + form state replay (old input / errors).
 */

namespace App\Core;

final class Flash
{
    public static function add($type, $message)
    {
        $messages = Session::get('__flash', array());
        $messages[] = array('type' => $type, 'message' => $message);
        Session::set('__flash', $messages);
    }

    public static function success($message)
    {
        self::add('success', $message);
    }

    public static function error($message)
    {
        self::add('error', $message);
    }

    public static function warning($message)
    {
        self::add('warning', $message);
    }

    public static function info($message)
    {
        self::add('info', $message);
    }

    public static function pull()
    {
        $messages = Session::get('__flash', array());
        Session::forget('__flash');
        return $messages;
    }

    /** Remember submitted input + validation errors for the next request. */
    public static function withInput(array $input, array $errors = array())
    {
        unset($input['_token'], $input['password'], $input['raw_password'], $input['admin_password']);
        Session::set('__old', $input);
        Session::set('__errors', $errors);
    }

    public static function clearInput()
    {
        Session::forget('__old');
        Session::forget('__errors');
    }
}