<?php
/**
 * Mailbox (account) management: create / update / enable / disable / password
 * reset / bulk import. Every write goes to the Mailu API first and is then
 * mirrored locally (including the password, so the console can hand out
 * "mail login information" sheets - encrypted with the app key).
 */

namespace App\Service;

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Logger;

final class UserService
{
    public static function paginate(array $filters = array(), $page = 1, $perPage = 50)
    {
        $where = array('1=1');
        $params = array();
        if (!empty($filters['server_id'])) {
            $where[] = 'u.server_id = :server_id';
            $params['server_id'] = (int) $filters['server_id'];
        }
        if (!empty($filters['domain'])) {
            $where[] = 'u.domain_name = :domain';
            $params['domain'] = strtolower($filters['domain']);
        }
        if (!empty($filters['q'])) {
            $where[] = '(u.email LIKE :q OR u.displayed_name LIKE :q OR u.comment LIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }
        if (isset($filters['status']) && $filters['status'] !== '') {
            $where[] = 'u.enabled = :status';
            $params['status'] = (int) $filters['status'];
        }
        if (!empty($filters['password_known'])) {
            $where[] = "u.password_enc IS NOT NULL AND u.password_enc <> ''";
        }
        $sql = implode(' AND ', $where);
        $total = (int) Database::value('SELECT COUNT(*) FROM {users} u WHERE ' . $sql, $params, 0);
        $perPage = max(5, min(500, (int) $perPage));
        $page = max(1, (int) $page);
        $offset = ($page - 1) * $perPage;
        $rows = Database::fetchAll(
            'SELECT u.*, s.name AS server_name FROM {users} u LEFT JOIN {servers} s ON s.id = u.server_id'
            . ' WHERE ' . $sql . ' ORDER BY u.email ASC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );
        return array('items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => (int) ceil($total / $perPage));
    }

    public static function all(array $filters = array())
    {
        $result = self::paginate($filters, 1, 5000);
        return $result['items'];
    }

    public static function find($id)
    {
        return Database::fetch('SELECT * FROM {users} WHERE id = ?', array((int) $id));
    }

    public static function findOrFail($id)
    {
        $user = self::find($id);
        if (!$user) {
            throw new \RuntimeException('è´¦å·ä¸å­˜åœ¨: #' . (int) $id);
        }
        return $user;
    }

    public static function findEmail($serverId, $email)
    {
        return Database::fetch('SELECT * FROM {users} WHERE server_id = ? AND email = ?', array((int) $serverId, strtolower($email)));
    }

    /** Build the payload Mailu expects for POST /user or PATCH /user/{email}. */
    public static function payload(array $input, $isCreate)
    {
        $payload = array();
        $email = strtolower(trim((string) (isset($input['email']) ? $input['email'] : '')));
        if ($email === '' && !empty($input['local_part']) && !empty($input['domain_name'])) {
            $email = strtolower(trim($input['local_part']) . '@' . trim($input['domain_name']));
        }
        if ($email !== '') {
            $payload['email'] = $email;
        }
        if (!empty($input['password'])) {
            $payload['raw_password'] = (string) $input['password'];
        }
        if (array_key_exists('quota_mb', $input) && $input['quota_mb'] !== '' && $input['quota_mb'] !== null) {
            $payload['quota_bytes'] = (int) round(((float) $input['quota_mb']) * 1048576);
        }
        if (array_key_exists('comment', $input)) {
            $payload['comment'] = (string) $input['comment'];
        }
        if (array_key_exists('displayed_name', $input)) {
            $payload['displayed_name'] = (string) $input['displayed_name'];
        }
        foreach (array('enabled', 'global_admin', 'enable_imap', 'enable_pop', 'spam_enabled', 'change_pw_next_login') as $flag) {
            if (array_key_exists($flag, $input)) {
                $payload[$flag] = (bool) $input[$flag];
            }
        }
        if (array_key_exists('forward_enabled', $input)) {
            $payload['forward_enabled'] = (bool) $input['forward_enabled'];
            $destinations = self::destinationList(isset($input['forward_destination']) ? $input['forward_destination'] : '');
            if ($input['forward_enabled'] && !$destinations) {
                throw new \RuntimeException('å¯ç”¨é‚®ä»¶è½¬å‘æ—¶å¿…é¡»å¡«å†™è‡³å°‘ä¸€ä¸ªè½¬å‘ç›®æ ‡åœ°å€');
            }
            $payload['forward_destination'] = $destinations;
        }
        if (array_key_exists('spam_threshold', $input) && $input['spam_threshold'] !== '' && $input['spam_threshold'] !== null) {
            $payload['spam_threshold'] = (int) $input['spam_threshold'];
        }
        return $payload;
    }

    public static function destinationList($value)
    {
        if (is_array($value)) {
            $parts = $value;
        } else {
            $parts = preg_split('/[\s,;]+/', (string) $value);
        }
        $out = array();
        foreach ((array) $parts as $part) {
            $part = strtolower(trim((string) $part));
            if ($part !== '' && filter_var($part, FILTER_VALIDATE_EMAIL)) {
                $out[] = $part;
            }
        }
        return array_values(array_unique($out));
    }

    public static function create($serverId, array $input, $options = array())
    {
        $server = ServerService::findOrFail($serverId);
        $payload = self::payload($input, true);
        if (empty($payload['email'])) {
            throw new \RuntimeException('ç¼ºå°‘é‚®ç®±åœ°å€');
        }
        if (!filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('é‚®ç®±åœ°å€æ ¼å¼ä¸æ­£ç¡®: ' . $payload['email']);
        }
        $password = isset($payload['raw_password']) ? $payload['raw_password'] : '';
        if ($password === '') {
            $password = random_password((int) Config::get('mailu.default_password_length', 16));
            $payload['raw_password'] = $password;
        }
        $parts = explode('@', $payload['email']);
        $domainName = $parts[1];
        $domain = Database::fetch('SELECT * FROM {domains} WHERE server_id = ? AND name = ?', array((int) $serverId, $domainName));
        if (!$domain) {
            // domain not in the local mirror yet - check the server and mirror it
            $client = ServerService::client($server);
            $remote = $client->getDomain($domainName);
            if (!is_array($remote)) {
                throw new \RuntimeException('åŸŸå ' . $domainName . ' åœ¨è¯¥é‚®ä»¶æœåŠ¡å™¨ä¸Šä¸å­˜åœ¨');
            }
        }
        $client = ServerService::client($server);
        $client->createUser($payload);
        $user = self::mirrorFromRemote($client, $serverId, $payload['email'], $password);
        AuditService::log('user.create', 'ok', array(
            'email' => $payload['email'],
            'quota_mb' => isset($payload['quota_bytes']) ? bytes_to_mb($payload['quota_bytes']) : null,
            'global_admin' => !empty($payload['global_admin']),
        ), array('server_id' => (int) $serverId, 'target_type' => 'user', 'target_label' => $payload['email']));
        return array('ok' => true, 'user' => $user, 'password' => $password, 'message' => 'è´¦å·åˆ›å»ºæˆåŠŸ');
    }

    /** Refresh one account from the API and keep the given password locally. */
    public static function mirrorFromRemote(MailuClient $client, $serverId, $email, $password = null)
    {
        $detail = $client->getUser($email);
        if (!is_array($detail)) {
            $detail = array('email' => $email, 'enabled' => true);
        }
        $parts = explode('@', strtolower($email));
        $domainName = isset($parts[1]) ? $parts[1] : '';
        $domainId = Database::value('SELECT id FROM {domains} WHERE server_id = ? AND name = ?', array((int) $serverId, $domainName), null);
        $data = array(
            'server_id' => (int) $serverId,
            'domain_id' => $domainId ? (int) $domainId : null,
            'email' => strtolower($email),
            'local_part' => $parts[0],
            'domain_name' => $domainName,
            'displayed_name' => isset($detail['displayed_name']) ? (string) $detail['displayed_name'] : '',
            'comment' => isset($detail['comment']) ? (string) $detail['comment'] : '',
            'quota_bytes' => isset($detail['quota_bytes']) ? (int) $detail['quota_bytes'] : 0,
            'quota_bytes_used' => isset($detail['quota_bytes_used']) ? (int) $detail['quota_bytes_used'] : 0,
            'enabled' => isset($detail['enabled']) ? (!empty($detail['enabled']) ? 1 : 0) : 1,
            'global_admin' => !empty($detail['global_admin']) ? 1 : 0,
            'enable_imap' => array_key_exists('enable_imap', $detail) ? (!empty($detail['enable_imap']) ? 1 : 0) : 1,
            'enable_pop' => array_key_exists('enable_pop', $detail) ? (!empty($detail['enable_pop']) ? 1 : 0) : 1,
            'forward_enabled' => !empty($detail['forward_enabled']) ? 1 : 0,
            'forward_destination' => isset($detail['forward_destination']) ? json_encode((array) $detail['forward_destination'], JSON_UNESCAPED_UNICODE) : null,
            'spam_enabled' => array_key_exists('spam_enabled', $detail) ? (!empty($detail['spam_enabled']) ? 1 : 0) : 1,
            'spam_threshold' => isset($detail['spam_threshold']) ? (int) $detail['spam_threshold'] : null,
            'remote_exists' => 1,
            'synced_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        );
        if ($password !== null && $password !== '') {
            $data['password_enc'] = Crypto::encrypt($password);
            $data['password_set_at'] = date('Y-m-d H:i:s');
            $data['password_source'] = 'console';
        }
        $existing = self::findEmail($serverId, $email);
        if ($existing) {
            Database::update('users', $data, 'id = :id', array('id' => (int) $existing['id']));
            return self::find((int) $existing['id']);
        }
        $data['created_at'] = date('Y-m-d H:i:s');
        $id = Database::insert('users', $data);
        return self::find($id);
    }

    public static function update($id, array $input)
    {
        $user = self::findOrFail($id);
        $server = ServerService::findOrFail($user['server_id']);
        $payload = self::payload($input, false);
        unset($payload['email']);
        if (!$payload) {
            throw new \RuntimeException('æ²¡æœ‰éœ€è¦æ›´æ–°çš„å­—æ®µ');
        }
        $client = ServerService::client($server);
        $client->updateUser($user['email'], $payload);
        $password = null;
        if (isset($payload['raw_password'])) {
            $password = $payload['raw_password'];
        }
        $updated = self::mirrorFromRemote($client, (int) $user['server_id'], $user['email'], $password);
        AuditService::log('user.update', 'ok', array('fields' => array_keys($payload)), array(
            'server_id' => (int) $user['server_id'], 'target_type' => 'user', 'target_label' => $user['email'],
        ));
        return $updated;
    }

    public static function delete($id)
    {
        $user = self::findOrFail($id);
        $server = ServerService::findOrFail($user['server_id']);
        $client = ServerService::client($server);
        $client->deleteUser($user['email']);
        Database::delete('users', 'id = :id', array('id' => (int) $id));
        AuditService::log('user.delete', 'ok', array('email' => $user['email']), array(
            'server_id' => (int) $user['server_id'], 'target_type' => 'user', 'target_label' => $user['email'],
        ));
        return true;
    }

    public static function toggle($id)
    {
        $user = self::findOrFail($id);
        $server = ServerService::findOrFail($user['server_id']);
        $client = ServerService::client($server);
        $newState = (int) $user['enabled'] === 1 ? false : true;
        $client->updateUser($user['email'], array('enabled' => $newState));
        Database::update('users', array('enabled' => $newState ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')), 'id = :id', array('id' => (int) $id));
        AuditService::log('user.toggle', 'ok', array('enabled' => $newState), array(
            'server_id' => (int) $user['server_id'], 'target_type' => 'user', 'target_label' => $user['email'],
        ));
        return $newState;
    }

    public static function setPassword($id, $password = null, $source = 'console')
    {
        $user = self::findOrFail($id);
        $server = ServerService::findOrFail($user['server_id']);
        if ($password === null || $password === '') {
            $password = random_password((int) Config::get('mailu.default_password_length', 16));
        }
        $client = ServerService::client($server);
        $client->updateUser($user['email'], array('raw_password' => $password));
        Database::update('users', array(
            'password_enc' => Crypto::encrypt($password),
            'password_set_at' => date('Y-m-d H:i:s'),
            'password_source' => $source,
            'updated_at' => date('Y-m-d H:i:s'),
        ), 'id = :id', array('id' => (int) $id));
        AuditService::log('user.password', 'ok', array('source' => $source), array(
            'server_id' => (int) $user['server_id'], 'target_type' => 'user', 'target_label' => $user['email'],
        ));
        return $password;
    }

    /**
     * Bulk create. Accepts one account per line:
     *   email,password,quota_mb,display name,comment
     * Only the e-mail is mandatory; separators can be comma, semicolon, pipe or tab.
     */
    public static function bulk($serverId, $text, array $options = array())
    {
        $lines = preg_split('/\r?\n/', (string) $text);
        $results = array();
        $domainCache = array();
        $created = 0;
        $failed = 0;
        foreach ($lines as $number => $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }
   `*      $pa2ts09 pregstlit(&+\s*Y,;|\t]\r*/', $li.e);
      0     $email =(stv4olower(trim($part3[1]));   "        iF¡(strp}s($emahl, 'P') 5== balwE && !empty$options['domamn'])) {
                dgmail = ÷Trtolower($eliil . '@% . urim($options['doma)n'Ý));
            }
           parswrd = kssdt($part{Y1y) ? trim(%pArts[1M) 8 #;            $quouá = issmt($parts:M) && t2io($pabts[2M) )==$'§ ? |rim($pñrus[]) > ¨issmx($options[7qu/ta_}b§]) ? $Opöaonó['quota_mb'] >d'');
         ` $nam- <"isbet($pa2ts[]) ?$trim($parts[3]	 : '&;
 "`0       $commdot = isset $parts[4]) ? trim(,parts[4]) * '';
            if!(!filtuò_var($email, FILTR]VALIDATE_EMAKL)© {
        0  `   $4resõlts[] = arr`y('line' => $number « 1, 'eMail' }6 email( 'ok' =? false© 'messáge§ =/ 'é‚¦ç®±æ œå¸d¸æ½£ç¡®');
 a        $(   `$failed+*9
  `      0   !( continuå:
  (       `0}
 $    (     try {
        ¢  0   "$resUlt - sE|f::cruate($wervgrIdlarrayh
                  p 'amcil' =6 ¥email,     (      " (     'rassword' = $passwor$,     ¢  &    `      'quota_mbg => $quota-     b         8!  $'disrlayed_name' =>04.ame,
      $` !     0"   'colment' =>0$commEnt,
     !      " (     'Gnajlåd# =? Trum,!$       !      ));
 `     (        &2esults[\ = array*'minå'"=> $~umberp+ 1, 'email' => $amail, 'ok' => truu, 'messC'ec =. &å·²åˆ›å»»', 'rassword' => $result[fpassorg']);
  "      $0  $ $creatdd)+;
       ( 0  }$catch (]ÔhrogaBle $e)${
     !  (       $re3ulTs[] < err!y('lineg => %numb%r"k`1, /eiqil' 9. $email< 'ok' => false, 'messaoe' =>`$e%>getMdssage());       0 $`!0  $$failed++{
            `$  Logger::varo('bqlk creata0faéled7L aòray(d}áil' =>"$emaiì, 'error' => $e->getMessege,)));
  "`  (     }
        |
 "      Audit×errice::log('usur&belk'l $fAiìed >00 ? 'part)a,/ : 'ïk', a0ray('creaôgd' =>0$cr%ateD, 'faalmd' => &æeilEd),(azr`y(
!       !   ¦seruur_iD7 => (ifty $servepId, 'target_type' =>!'user', 'tareet_,ibel' => 'æÈùé‡‹åˆ›å¹º',
   2    ));
        return0arr!y(/created' =~ $ireated, 'faile$' => $faile`, 'results'$=> $results);
    }

    /** Quota usage rows for the dashboard / reports. */
    public static function topUsage($limit = 10)
    {
        return Database::fetchAll(
            'SELECT u.*, s.name AS server_name FROM {users} u LEFT JOIN {servers} s ON s.id = u.server_id'
            . ' WHERE u.quota_bytes > 0 ORDER BY (CAST(u.quota_bytes_used AS FLOAT) / u.quota_bytes) DESC LIMIT ' . (int) $limit
        );
    }

    public static function exportRows(array $filters = array())
    {
        $rows = self::all($filters);
        $out = array();
        foreach ($rows as $row) {
            $out[] = array(
                'server' => isset($row['server_name']) ? $row['server_name'] : '',
                'email' => $row['email'],
                'displayed_name' => $row['displayed_name'],
                'quota_mb' => $row['quota_bytes'] > 0 ? round($row['quota_bytes'] / 1048576, 2) : 0,
                'used_mb' => round($row['quota_bytes_used'] / 1048576, 2),
                'enabled' => (int) $row['enabled'] === 1 ? 'yes' : 'no',
                'global_admin' => (int) $row['global_admin'] === 1 ? 'yes' : 'no',
                'forward_to' => implode(' ', json_field($row['forward_destination'])),
                'comment' => $row['comment'],
                'synced_at' => $row['synced_at'],
            );
        }
        return $out;
    }
}