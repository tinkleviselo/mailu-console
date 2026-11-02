<?php
/**
 * Token authentication for the console's own JSON API (used by external
 * automation, e.g. a billing / provisioning panel).
 */

namespace App\Core;

final class Api
{
    public static function requireToken()
    {
        $token = Request::bearerToken();
        $expected = (string) Settings::get('api.bearer_hash', '');
        if ($token !== '' && $expected !== '' && hash_equals($expected, hash('sha256', $token))) {
            $GLOBALS['__api_identity'] = 'api-token';
            return;
        }
        if (Auth::check()) {
            $GLOBALS['__api_identity'] = Auth::name();
            return;
        }
        Response::json(array('ok' => false, 'error' => 'API 令牌无效或缺失'), 401);
    }

    public static function identity()
    {
        return isset($GLOBALS['__api_identity']) ? $GLOBALS['__api_identity'] : 'api';
    }
}