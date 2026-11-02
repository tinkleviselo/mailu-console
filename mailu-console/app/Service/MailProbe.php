<?php
/**
 * Live login probes: verifies that a mailbox can log in over IMAP / POP3 and
 * authenticate on the SMTP submission port (nothing is ever sent).
 */

namespace App\Service;

use App\Core\Logger;

final class MailProbe
{
    private static $timeout = 8;

    public static function run(array $server, $email, $password, array $tests = array('imap', 'smtp'))
    {
        $ep = CredentialService::endpoints($server);
        $results = array();
        if (in_array('imap', $tests, true)) {
            $results['imap'] = self::imap($ep['imap'], $email, $password);
        }
        if (in_array('pop3', $tests, true)) {
            $results['pop3'] = self::pop3($ep['pop3'], $email, $password);
        }
        if (in_array('smtp', $tests, true)) {
            $results['smtp'] = self::smtp($ep['smtp'], $email, $password);
            if (!empty($ep['smtp']['alt_port']) && empty($results['smtp']['ok'])) {
                $alt = array('host' => $ep['smtp']['host'], 'port' => $ep['smtp']['alt_port'], 'security' => $ep['smtp']['alt_security']);
                $results['smtp_alt'] = self::smtp($alt, $email, $password);
            }
        }
        if (in_array('sieve', $tests, true)) {
            $results['sieve'] = self::tcpCheck($ep['sieve']['host'], (int) $ep['sieve']['port']);
        }
        return $results;
    }

    public static function imap(array $endpoint, $email, $password)
    {
        $start = microtime(true);
        $result = array('ok' => false, 'target' => $endpoint['host'] . ':' . $endpoint['port'], 'message' => '', 'protocol' => 'IMAP', 'ms' => 0);
        $fp = self::connect($endpoint);
        if (!$fp) {
            $result['message'] = '无法建立连接（端口被防火墙拦截，或服务未启动）';
            return $result;
        }
        $banner = self::read($fp);
        if (stripos($banner, 'OK') === false && stripos($banner, 'PREAUTH') === false) {
            $result['message'] = 'IMAP 服务响应异常: ' . trim(str_limit($banner, 80, ''));
            fclose($fp);
            return $result;
        }
        $tag = 'a1';
        $login = $tag . ' LOGIN ' . self::quote($email) . ' ' . self::quote($password) . "\r\n";
        @fwrite($fp, $login);
        $response = self::read($fp, $tag);
        @fwrite($fp, 'a2 LOGOUT' . "\r\n");
        fclose($fp);
        $result['ms'] = (int) round((microtime(true) - $start) * 1000);
        if (stripos($response, $tag . ' OK') !== false) {
            $result['ok'] = true;
            $result['message'] = 'IMAP 登录成功，可以正常收信';
        } else {
            $result['message'] = self::explain($response, 'IMAP');
        }
        return $result;
    }

    public static function pop3(array $endpoint, $email, $password)
    {
        $start = microtime(true);
        $result = array('ok' => false, 'target' => $endpoint['host'] . ':' . $endpoint['port'], 'message' => '', 'protocol' => 'POP3', 'ms' => 0);
        $fp = self::connect($endpoint);
        if (!$fp) {
            $result['message'] = '无法建立连接（端口被防火墙拦截，或服务未启动）';
            return $result;
        }
        self::read($fp);
        @fwrite($fp, 'USER ' . $email . "\r\n");
        $userResponse = self::read($fp);
        @fwrite($fp, 'PASS ' . $password . "\r\n");
        $passResponse = self::read($fp);
        @fwrite($fp, 'QUIT' . "\r\n");
        fclose($fp);
        $result['ms'] = (int) round((microtime(true) - $start) * 1000);
        if (stripos($passResponse, '+OK') !== false) {
            $result['ok'] = true;
            $result['message'] = 'POP3 登录成功';
        } else {
            $result['message'] = self::explain($passResponse . ' ' . $userResponse, 'POP3');
        }
        return $result;
    }

    public static function smtp(array $endpoint, $email, $password)
    {
        $start = microtime(true);
        $result = array('ok' => false, 'target' => $endpoint['host'] . ':' . $endpoint['port'], 'message' => '', 'protocol' => 'SMTP', 'ms' => 0);
        $fp = self::connect($endpoint, true);
        if (!$fp) {
            $result['message'] = '无法建立连接（端口被防火墙拦截，或服务未启动）';
            return $result;
        }
        $banner = self::read($fp);
        if (strpos($banner, '220') === false) {
            $result['message'] = 'SMTP 服务响应异常: ' . trim(str_limit($banner, 80, ''));
            fclose($fp);
            return $result;
        }
        @fwrite($fp, "EHLO mailu-console.local\r\n");
        $ehlo = self::read($fp);
        if (strtoupper((string) $endpoint['security']) === 'starttls') {
            @fwrite($fp, "STARTTLS\r\n");
            $starttls = self::read($fp);
            if (strpos($starttls, '220') === false) {
                $result['message'] = 'STARTTLS 不可用: ' . trim(str_limit($starttls, 80, ''));
                fclose($fp);
                return $result;
            }
            $crypto = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($crypto !== true) {
                $result['message'] = 'TLS 握手失败';
                fclose($fp);
                return $result;
            }
            @fwrite($fp, "EHLO mailu-console.local\r\n");
            $ehlo = self::read($fp);
        }
        // AUTH LOGIN
        @fwrite($fp, "AUTH LOGIN\r\n");
        $authPrompt = self::read($fp);
        if (strpos($authPrompt, '334') === false) {
            $result['message'] = 'SMTP 服务器未提供 AUTH LOGIN（响应: ' . trim(str_limit($authPrompt, 60, '')) . '）';
            @fwrite($fp, "QUIT\r\n");
            fclose($fp);
            return $result;
        }
        @fwrite($fp, base64_encode($email) . "\r\n");
        self::read($fp);
        @fwrite($fp, base64_encode($password) . "\r\n");
        $authResult = self::read($fp);
        @fwrite($fp, "QUIT\r\n");
        fclose($fp);
        $result['ms'] = (int) round((microtime(true) - $start) * 1000);
        if (strpos($authResult, '235') !== false) {
            $result['ok'] = true;
            $result['message'] = 'SMTP 认证成功，可以正常发信';
        } else {
            $result['message'] = self::explain($authResult, 'SMTP 认证');
        }
        return $result;
    }

    public static function tcpCheck($host, $port)
    {
        $start = microtime(true);
        $fp = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, self::$timeout);
        $result = array(
            'ok' => (bool) $fp,
            'target' => $host . ':' . $port,
            'protocol' => 'TCP',
            'ms' => (int) round((microtime(true) - $start) * 1000),
            'message' => $fp ? '端口可达' : ('端口不可达: ' . $errstr),
        );
        if ($fp) {
            fclose($fp);
        }
        return $result;
    }

    private static function connect(array $endpoint, $implicitTlsOnly = false)
    {
        $host = (string) $endpoint['host'];
        $port = (int) $endpoint['port'];
        $security = strtolower((string) (isset($endpoint['security']) ? $endpoint['security'] : 'ssl'));
        if ($host === '' || $port <= 0) {
            return false;
        }
        $remote = ($security === 'ssl' || $security === 'tls') ? 'ssl://' : 'tcp://';
        $context = stream_context_create(array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true)));
        $fp = @stream_socket_client($remote . $host . ':' . $port, $errno, $errstr, self::$timeout, STREAM_CLIENT_CONNECT, $context);
        if (!$fp) {
            Logger::warn('probe connect failed', array('target' => $host . ':' . $port, 'error' => $errstr));
            return false;
        }
        stream_set_timeout($fp, self::$timeout);
        return $fp;
    }

    private static function read($fp, $tag = null)
    {
        $data = '';
        $deadline = time() + self::$timeout;
        while (!feof($fp) && time() < $deadline) {
            $chunk = fgets($fp, 2048);
            if ($chunk === false) {
                break;
            }
            $data .= $chunk;
            if ($tag !== null) {
                if (stripos($chunk, $tag . ' ') === 0) {
                    break;
                }
            } elseif (preg_match('/^\d{3} /', $chunk) || preg_match('/^\+OK/i', $chunk) || preg_match('/^\* OK/i', $chunk)) {
                break;
            }
        }
        return $data;
    }

    private static function quote($value)
    {
        return '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), (string) $value) . '"';
    }

    private static function explain($response, $context)
    {
        $response = trim((string) $response);
        if (stripos($response, 'AUTHENTICATIONFAILED') !== false || stripos($response, 'authentication failed') !== false || stripos($response, '535') !== false) {
            return $context . ' 认证失败：账号或密码不正确，或账号已被禁用';
        }
        if (stripos($response, 'disabled') !== false) {
            return $context . ' 认证失败：账号在邮件服务器上被禁用';
        }
        if (stripos($response, 'NO [PRIVACYREQUIRED]') !== false || stripos($response, 'plaintext') !== false) {
            return $context . ' 被拒绝：服务器要求先启用 TLS 加密';
        }
        return $context . ' 失败：' . trim(str_limit($response, 160, ''));
    }
}