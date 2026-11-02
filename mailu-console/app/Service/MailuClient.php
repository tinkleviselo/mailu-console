<?php
/**
 * Mailu REST API client (Mailu >= 2.0, /api/v1).
 *
 *  - Bearer token authentication (API_TOKEN in mailu.env) or HTTP basic auth
 *  - normalises the different response shapes used across Mailu releases
 *  - transparently falls back between PATCH/PUT for updates
 *
 * Documented endpoints used here:
 *   GET    /api/v1/domain                     list domains
 *   POST   /api/v1/domain                     create domain
 *   GET    /api/v1/domain/{name}              domain details (incl. dns_* records)
 *   PATCH  /api/v1/domain/{name}              update domain
 *   DELETE /api/v1/domain/{name}              delete domain
 *   GET    /api/v1/domain/{name}/users        users of a domain
 *   POST   /api/v1/domain/{name}/dkim         generate DKIM / DMARC keys
 *   GET    /api/v1/user                       list users
 *   POST   /api/v1/user                       create user
 *   GET    /api/v1/user/{email}               user details
 *   PATCH  /api/v1/user/{email}               update user
 *   DELETE /api/v1/user/{email}               delete user
 *   GET    /api/v1/alias                      list aliases
 *   POST   /api/v1/alias                      create alias
 *   GET    /api/v1/alias/{email}              alias details
 *   PATCH  /api/v1/alias/{email}              update alias
 *   DELETE /api/v1/alias/{email}              delete alias
 *   GET    /api/v1/relay                      list relays
 *   POST   /api/v1/relay                      create relay
 *   DELETE /api/v1/relay/{name}               delete relay
 *   GET    /api/v1/token                      list API tokens
 *   POST   /api/v1/token                      create API token for a user
 */

namespace App\Service;

use App\Core\Logger;

final class MailuClient
{
    private $server;
    private $baseUrl;
    private $apiPath;
    private $token;
    private $basicUser;
    private $basicPass;
    private $verifyTls;
    private $timeout;

    public $lastStatus = 0;
    public $lastError = '';
    public $lastBody = '';

    public function __construct(array $server, $token = '', $basicUser = '', $basicPass = '')
    {
        $this->server = $server;
        $base = isset($server['base_url']) ? trim((string) $server['base_url']) : '';
        $this->baseUrl = rtrim($base, '/');
        $apiPath = isset($server['api_path']) && $server['api_path'] !== '' ? (string) $server['api_path'] : '/api/v1';
        $this->apiPath = '/' . trim($apiPath, '/');
        $this->token = trim((string) $token);
        $this->basicUser = trim((string) $basicUser);
        $this->basicPass = (string) $basicPass;
        $this->verifyTls = !isset($server['verify_tls']) || (int) $server['verify_tls'] === 1;
        $this->timeout = isset($server['timeout']) && (int) $server['timeout'] > 0 ? (int) $server['timeout'] : 20;
    }

    public function serverId()
    {
        return isset($this->server['id']) ? (int) $this->server['id'] : 0;
    }

    public function baseUrl()
    {
        return $this->baseUrl;
    }

    public function url($path = '')
    {
        if ($path === '' || $path === null) {
            return $this->baseUrl . $this->apiPath;
        }
        if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) {
            return $path;
        }
        return $this->baseUrl . $this->apiPath . '/' . ltrim($path, '/');
    }

    /** Web (admin / webmail) URL of the instance, without the API path. */
    public function webUrl($path = '')
    {
        return $this->baseUrl . ($path === '' ? '' : '/' . ltrim($path, '/'));
    }

    // ---------------------------------------------------------------- transport

    public function request($method, $path, $body = null, array $query = array(), $allow404 = false)
    {
        $url = $this->url($path);
        if ($query) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
        }
        $method = strtoupper($method);
        $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $attempts = 0;
        $lastMessage = '';

        while ($attempts < 2) {
            $attempts++;
            $result = $this->send($method, $url, $payload);
            $this->lastStatus = $result['status'];
            $this->lastBody = $result['body'];
            $this->lastError = $result['error'];

            if ($result['status'] === 0) {
                $lastMessage = $result['error'] !== '' ? $result['error'] : '无法连接到邮件服务器';
                if ($attempts < 2) {
                    usleep(300000);
                    continue;
                }
                Logger::error('mailu api unreachable', array('url' => $url, 'error' => $lastMessage));
                throw new MailuException($lastMessage, 0, $url);
            }
            if ($result['status'] >= 400) {
                $decoded = $this->decode($result['body']);
                $message = '';
                if (is_array($decoded)) {
                    foreach (array('message', 'detail', 'error', 'msg') as $key) {
                        if (!empty($decoded[$key]) && is_string($decoded[$key])) {
                            $message = $decoded[$key];
                            break;
                        }
                    }
                    if ($message === '' && isset($decoded['errors']) && is_array($decoded['errors'])) {
                        $message = json_encode($decoded['errors'], JSON_UNESCAPED_UNICODE);
                    }
                }
                if ($message === '') {
                    $message = 'HTTP ' . $result['status'] . ' (' . $method . ' ' . $this->shortPath($url) . ')';
                }
                if ($result['status'] === 404 && $allow404) {
                    return null;
                }
                throw new MailuException($message, $result['status'], $url);
            }
            $decoded = $this->decode($result['body']);
            return $decoded === null ? array() : $decoded;
        }
        throw new MailuException($lastMessage !== '' ? $lastMessage : '请求失败', 0, $url);
    }

    private function shortPath($url)
    {
        $parts = parse_url($url);
        $path = isset($parts['path']) ? $parts['path'] : $url;
        if (!empty($parts['query'])) {
            $path .= '?' . $parts['query'];
        }
        return $path;
    }

    /** Send a raw request; returns array(status, body, error). */
    private function send($method, $url, $payload)
    {
        if (function_exists('curl_init')) {
            return $this->sendCurl($method, $url, $payload);
        }
        return $this->sendStream($method, $url, $payload);
    }

    private function headers()
    {
        $headers = array(
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: MailuConsole/' . (defined('MC_VERSION') ? MC_VERSION : '1.0'),
        );
        if ($this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        } elseif ($this->basicUser !== '') {
            $headers[] = 'Authorization: Basic ' . base64_encode($this->basicUser . ':' . $this->basicPass);
        }
        return $headers;
    }

    private function sendCurl($method, $url, $payload)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers());
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $this->timeout));
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verifyTls);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->verifyTls ? 2 : 0);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            return array('status' => 0, 'body' => '', 'error' => 'cURL: ' . $error);
        }
        return array('status' => $status, 'body' => (string) $body, 'error' => '');
    }

    private function sendStream($method, $url, $payload)
    {
        $context = array(
            'http' => array(
                'method' => $method,
                'header' => implode("\r\n", $this->headers()),
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ),
            'ssl' => array(
                'verify_peer' => $this->verifyTls,
                'verify_peer_name' => $this->verifyTls,
            ),
        );
        if ($payload !== null) {
            $context['http']['content'] = $payload;
        }
        $body = @file_get_contents($url, false, stream_context_create($context));
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }
        if ($body === false) {
            return array('status' => 0, 'body' => '', 'error' => 'stream: ' . ($status === 0 ? '连接失败' : 'HTTP ' . $status));
        }
        return array('status' => $status, 'body' => (string) $body, 'error' => '');
    }

    private function decode($body)
    {
        if ($body === '' || $body === null) {
            return array();
        }
        $decoded = json_decode((string) $body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return array('raw' => (string) $body);
        }
        return $decoded;
    }

    /**
     * Mailu list endpoints return either a bare list, a list of objects or a
     * paginated envelope. Normalise all of them to a list of arrays.
     */
    public function normaliseList($decoded)
    {
        if (!is_array($decoded)) {
            return array();
        }
        foreach (array('items', 'data', 'results', 'objects') as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key])) {
                $decoded = $decoded[$key];
                break;
            }
        }
        $out = array();
        foreach ($decoded as $key => $item) {
            if (is_string($item)) {
                $out[] = array('name' => $item, 'email' => $item);
            } elseif (is_array($item)) {
                $out[] = $item;
            }
        }
        return $out;
    }

    /** Try PATCH first, then PUT (older Mailu releases only ship PUT). */
    private function update($path, array $data)
    {
        try {
            return $this->request('PATCH', $path, $data);
        } catch (MailuException $e) {
            if (in_array($e->status(), array(404, 405, 501), true)) {
                return $this->request('PUT', $path, $data);
            }
            throw $e;
        }
    }

    // ------------------------------------------------------------------ health

    public function ping()
    {
        try {
            $domains = $this->listDomains();
            return array(
                'ok' => true,
                'status' => $this->lastStatus,
                'message' => 'API 可用',
                'domains' => count($domains),
            );
        } catch (MailuException $e) {
            $hint = $e->status() === 401 || $e->status() === 403
                ? 'API 令牌无效（请在 Mailu 的 mailu.env 中检查 API_TOKEN 与 API=true，或在中台重新填写令牌）'
                : $e->getMessage();
            return array('ok' => false, 'status' => $e->status(), 'message' => $hint, 'domains' => 0);
        }
    }

    /** Best effort: read the swagger document to verify the API version. */
    public function swaggerInfo()
    {
        try {
            $data = $this->request('GET', '/swagger.json');
            if (is_array($data) && isset($data['info'])) {
                return $data['info'];
            }
            return is_array($data) ? $data : array();
        } catch (MailuException $e) {
            return array();
        }
    }

    // ----------------------------------------------------------------- domains

    public function listDomains()
    {
        return $this->normaliseList($this->request('GET', '/domain'));
    }

    public function getDomain($name)
    {
        return $this->request('GET', '/domain/' . rawurlencode($name), null, array(), true);
    }

    public function createDomain(array $data)
    {
        return $this->request('POST', '/domain', $data);
    }

    public function updateDomain($name, array $data)
    {
        return $this->update('/domain/' . rawurlencode($name), $data);
    }

    public function deleteDomain($name)
    {
        return $this->request('DELETE', '/domain/' . rawurlencode($name));
    }

    public function domainUsers($name)
    {
        return $this->normaliseList($this->request('GET', '/domain/' . rawurlencode($name) . '/users'));
    }

    public function generateDkim($name)
    {
        return $this->request('POST', '/domain/' . rawurlencode($name) . '/dkim');
    }

    public function listAlternatives()
    {
        return $this->normaliseList($this->request('GET', '/alternative'));
    }

    public function createAlternative($domain, $name)
    {
        return $this->request('POST', '/alternative', array('domain' => $domain, 'name' => $name));
    }

    public function deleteAlternative($name)
    {
        return $this->request('DELETE', '/alternative/' . rawurlencode($name));
    }

    // ------------------------------------------------------------------- users

    public function listUsers()
    {
        return $this->normaliseList($this->request('GET', '/user'));
    }

    public function getUser($email)
    {
        return $this->request('GET', '/user/' . rawurlencode($email), null, array(), true);
    }

    public function createUser(array $data)
    {
        return $this->request('POST', '/user', $data);
    }

    public function updateUser($email, array $data)
    {
        return $this->update('/user/' . rawurlencode($email), $data);
    }

    public function deleteUser($email)
    {
        return $this->request('DELETE', '/user/' . rawurlencode($email));
    }

    // ----------------------------------------------------------------- aliases

    public function listAliases()
    {
        return $this->normaliseList($this->request('GET', '/alias'));
    }

    public function getAlias($email)
    {
        return $this->request('GET', '/alias/' . rawurlencode($email), null, array(), true);
    }

    public function createAlias(array $data)
    {
        return $this->request('POST', '/alias', $data);
    }

    public function updateAlias($email, array $data)
    {
        return $this->update('/alias/' . rawurlencode($email), $data);
    }

    public function deleteAlias($email)
    {
        return $this->request('DELETE', '/alias/' . rawurlencode($email));
    }

    // ------------------------------------------------------------------ relays

    public function listRelays()
    {
        return $this->normaliseList($this->request('GET', '/relay'));
    }

    public function createRelay(array $data)
    {
        return $this->request('POST', '/relay', $data);
    }

    public function deleteRelay($name)
    {
        return $this->request('DELETE', '/relay/' . rawurlencode($name));
    }

    // ------------------------------------------------------------------ tokens

    public function listTokens()
    {
        return $this->normaliseList($this->request('GET', '/token'));
    }

    public function createToken($email, $comment = '')
    {
        return $this->request('POST', '/token', array('email' => $email, 'comment' => $comment));
    }
}