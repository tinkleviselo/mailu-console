<?php
/**
 * Console JSON API (/api/v1/*) for external provisioning systems.
 * Authentication: Authorization: Bearer <console API token> or an admin session.
 */

namespace App\Controller;

use App\Core\Request;
use App\Core\Response;
use App\Service\AliasService;
use App\Service\AuditService;
use App\Service\CredentialService;
use App\Service\DomainService;
use App\Service\ServerService;
use App\Service\SyncService;
use App\Service\UserService;

final class ApiController extends Controller
{
    public function ping()
    {
        $this->json(array(
            'ok' => true,
            'app' => 'Mailu Console',
            'version' => MC_VERSION,
            'time' => date('c'),
            'stats' => ServerService::stats(),
        ));
    }

    public function stats()
    {
        $this->json(array('ok' => true, 'data' => ServerService::stats()));
    }

    public function servers()
    {
        $data = array();
        foreach (ServerService::all() as $server) {
            $data[] = array(
                'id' => (int) $server['id'],
                'name' => $server['name'],
                'base_url' => $server['base_url'],
                'enabled' => (int) $server['enabled'] === 1,
                'domains' => (int) $server['domain_count'],
                'users' => (int) $server['user_count'],
                'aliases' => (int) $server['alias_count'],
                'last_check_ok' => $server['last_check_ok'] === null ? null : (int) $server['last_check_ok'] === 1,
                'last_check_at' => $server['last_check_at'],
                'last_sync_at' => $server['last_sync_at'],
            );
        }
        $this->json(array('ok' => true, 'data' => $data));
    }

    public function domains()
    {
        $filters = array('server_id' => Request::int('server_id', 0), 'q' => Request::str('q', ''));
        $data = array();
        foreach (DomainService::all($filters) as $domain) {
            $data[] = array(
                'id' => (int) $domain['id'],
                'server_id' => (int) $domain['server_id'],
                'name' => $domain['name'],
                'users' => (int) $domain['user_count'],
                'aliases' => (int) $domain['alias_count'],
                'max_users' => (int) $domain['max_users'],
                'dns' => isset($domain['dns']) ? $domain['dns'] : json_field($domain['dns_json']),
            );
        }
        $this->json(array('ok' => true, 'data' => $data));
    }

    public function users()
    {
        $filters = $this->filters();
        $result = UserService::paginate($filters, max(1, Request::int('page', 1)), min(500, max(1, Request::int('per_page', 100))));
        $data = array();
        foreach ($result['items'] as $user) {
            $data[] = array(
                'id' => (int) $user['id'],
                'server_id' => (int) $user['server_id'],
                'email' => $user['email'],
                'displayed_name' => $user['displayed_name'],
                'quota_bytes' => (int) $user['quota_bytes'],
                'quota_bytes_used' => (int) $user['quota_bytes_used'],
                'enabled' => (int) $user['enabled'] === 1,
                'global_admin' => (int) $user['global_admin'] === 1,
                'has_password' => !empty($user['password_enc']),
                'comment' => $user['comment'],
            );
        }
        $this->json(array('ok' => true, 'total' => $result['total'], 'page' => $result['page'], 'data' => $data));
    }

    public function aliases()
    {
        $filters = array('server_id' => Request::int('server_id', 0), 'domain' => Request::str('domain', ''), 'q' => Request::str('q', ''));
        $result = AliasService::paginate($filters, max(1, Request::int('page', 1)), 200);
        $data = array();
        foreach ($result['items'] as $alias) {
            $data[] = array(
                'id' => (int) $alias['id'],
                'server_id' => (int) $alias['server_id'],
                'email' => $alias['email'],
                'destination' => json_field($alias['destination']),
                'comment' => $alias['comment'],
            );
        }
        $this->json(array('ok' => true, 'total' => $result['total'], 'data' => $data));
    }

    public function createUser()
    {
        try {
            $serverId = Request::int('server_id', 0);
            if ($serverId <= 0) {
                throw new \RuntimeException('缺少 server_id');
            }
            $email = Request::str('email', Request::str('local_part') . '@' . Request::str('domain_name'));
            $password = (string) Request::input('password', '');
            if ($password === '') {
                $password = random_password(16);
            }
            $result = UserService::create($serverId, array(
                'email' => $email,
                'password' => $password,
                'quota_mb' => Request::has('quota_mb') ? Request::str('quota_mb') : '',
                'displayed_name' => Request::str('displayed_name'),
                'comment' => Request::str('comment'),
                'enabled' => Request::has('enabled') ? Request::bool('enabled') : true,
                'global_admin' => Request::bool('global_admin', false),
            ));
            $this->json(array('ok' => true, 'email' => $email, 'password' => $result['password']));
        } catch (\Throwable $e) {
            $this->json(array('ok' => false, 'error' => $e->getMessage()), 400);
        }
    }

    public function updateUser()
    {
        try {
            $user = $this->resolveUser();
            $payload = array();
            foreach (array('displayed_name', 'comment', 'quota_mb', 'spam_threshold') as $key) {
                if (Request::has($key)) {
                    $payload[$key] = Request::str($key);
                }
            }
            foreach (array('enabled', 'global_admin', 'enable_imap', 'enable_pop', 'forward_enabled', 'spam_enabled') as $key) {
                if (Request::has($key)) {
                    $payload[$key] = Request::bool($key);
                }
            }
            if (Request::has('forward_destination')) {
                $payload['forward_destination'] = Request::str('forward_destination');
            }
            $updated = UserService::update((int) $user['id'], $payload);
            $this->json(array('ok' => true, 'email' => $updated['email'], 'enabled' => (int) $updated['enabled'] === 1));
        } catch (\Throwable $e) {
            $this->json(array('ok' => false, 'error' => $e->getMessage()), 400);
        }
    }

    public function setPassword()
    {
        try {
            $user = $this->resolveUser();
            $password = (string) Request::input('password', '');
            if ($password === '') {
                $password = random_password(16);
            }
            UserService::setPassword((int) $user['id'], $password, 'api');
            $this->json(array('ok' => true, 'email' => $user['email'], 'password' => $password));
        } catch (\Throwable $e) {
            $this->json(array('ok' => false, 'error' => $e->getMessage()), 400);
        }
    }

    public function deleteUser()
    {
        try {
            $user = $this->resolveUser();
            UserService::delete((int) $user['id']);
            $this->json(array('ok' => true, 'email' => $user['email']));
        } catch (\Throwable $e) {
            $this->json(array('ok' => false, 'error' => $e->getMessage()), 400);
        }
    }

    public function createDomain()
    {
        try {
            $serverId = Request::int('server_id', 0);
            if ($serverId <= 0) {
                throw new \RuntimeException('缺少 server_id');
            }
            $domain = DomainService::create($serverId, $this->input());
            $this->json(array('ok' => true, 'domain' => $domain['name'], 'id' => (int) $domain['id']));
        } catch (\Throwable $e) {
            $this->json(array('ok' => false, 'error' => $e->getMessage()), 400);
        }
    }

    public function deleteDomain()
    {
        try {
            $domainId = Request::int('domain_id', 0);
            if ($domainId <= 0) {
                throw new \RuntimeException('缺少 domain_id');
            }
            DomainService::delete($domainId, Request::bool('delete_users', false));
            $this->json(array('ok' => true));
        } catch (\Throwable $e) {
            $this->json(array('ok' => false, 'error' => $e->getMessage()), 400);
        }
    }

    public function sync()
    {
        try {
            $serverId = Request::int('server_id', 0);
            if ($serverId > 0) {
                $result = SyncService::syncServer($serverId, Request::str('scope', 'all'));
                $this->json(array('ok' => $result['status'] === 'ok', 'result' => $result));
            }
            $this->json(array('ok' => true, 'result' => SyncService::syncAll(Request::str('scope', 'all'))));
        } catch (\Throwable $e) {
            $this->json(array('ok' => false, 'error' => $e->getMessage()), 400);
        }
    }

    public function credentials()
    {
        $user = $this->resolveUser();
        $server = ServerService::findOrFail($user['server_id']);
        $card = CredentialService::forUser($user, $server);
        $this->json(array(
            'ok' => true,
            'email' => $card['email'],
            'password' => $card['password'],
            'password_known' => $card['password_known'],
            'endpoints' => $card['endpoints'],
            'login_text' => CredentialService::asText($card),
        ));
    }

    private function resolveUser()
    {
        $id = Request::int('id', 0);
        if ($id > 0) {
            return UserService::findOrFail($id);
        }
        $email = Request::str('email');
        $serverId = Request::int('server_id', 0);
        if ($email === '') {
            throw new \RuntimeException('缺少 id 或 email');
        }
        if ($serverId > 0) {
            $user = UserService::findEmail($serverId, $email);
            if ($user) {
                return $user;
            }
        }
        $user = \App\Core\Database::fetch('SELECT * FROM {users} WHERE email = ? ORDER BY id ASC', array(strtolower($email)));
        if (!$user) {
            throw new \RuntimeException('账号不存在: ' . $email);
        }
        return $user;
    }
}