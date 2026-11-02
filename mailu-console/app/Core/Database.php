<?php
/**
 * Lazy PDO layer with a tiny migration runner. SQLite is the default storage
 * engine, MySQL/MariaDB is supported through config('db.driver').
 */

namespace App\Core;

final class Database
{
    private static $pdo = null;
    private static $migrated = false;

    public static function pdo()
    {
        if (self::$pdo instanceof \PDO) {
            return self::$pdo;
        }
        $driver = (string) Config::get('db.driver', 'sqlite');
        $options = array(
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        );
        try {
            if ($driver === 'mysql') {
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    Config::get('db.host', '127.0.0.1'),
                    (int) Config::get('db.port', 3306),
                    Config::get('db.name', Config::get('db.database', 'mailu_console'))
                );
                self::$pdo = new \PDO($dsn, (string) Config::get('db.username', 'root'), (string) Config::get('db.password', ''), $options);
            } else {
                $path = (string) Config::get('db.database', STORAGE_PATH . '/mailu.sqlite');
                if ($path === ':memory:' || strpos($path, '/') === 0 || preg_match('/^[A-Za-z]:/', $path)) {
                    $file = $path;
                } else {
                    $file = STORAGE_PATH . '/' . ltrim($path, '/');
                }
                if ($file !== ':memory:' && !is_dir(dirname($file))) {
                    @mkdir(dirname($file), 0750, true);
                }
                self::$pdo = new \PDO('sqlite:' . $file, null, null, $options);
                self::$pdo->exec('PRAGMA journal_mode = WAL');
                self::$pdo->exec('PRAGMA foreign_keys = ON');
                self::$pdo->exec('PRAGMA busy_timeout = 5000');
            }
        } catch (\PDOException $e) {
            Logger::error('database connection failed', array('driver' => $driver, 'error' => $e->getMessage()));
            throw new \RuntimeException('数据库连接失败: ' . $e->getMessage(), 0, $e);
        }
        return self::$pdo;
    }

    public static function isSqlite()
    {
        return (string) Config::get('db.driver', 'sqlite') !== 'mysql';
    }

    public static function table($name)
    {
        return (string) Config::get('db.prefix', 'mu_') . $name;
    }

    private static function q($sql)
    {
        $map = self::isSqlite()
            ? array('{PK}' => 'INTEGER PRIMARY KEY AUTOINCREMENT', '{STR}' => 'TEXT', '{INT}' => 'INTEGER', '{BIGINT}' => 'INTEGER', '{TEXT}' => 'TEXT', '{BOOL}' => 'INTEGER', '{TS}' => 'TEXT', '{JSON}' => 'TEXT', '{ENGINE}' => '')
            : array('{PK}' => 'INT AUTO_INCREMENT PRIMARY KEY', '{STR}' => 'VARCHAR(191)', '{INT}' => 'INT', '{BIGINT}' => 'BIGINT', '{TEXT}' => 'LONGTEXT', '{BOOL}' => 'TINYINT(1)', '{TS}' => 'DATETIME', '{JSON}' => 'LONGTEXT', '{ENGINE}' => ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $tables = array();
        foreach (array('admins', 'settings', 'servers', 'domains', 'users', 'aliases', 'audit_logs', 'deploy_scripts', 'deploy_jobs', 'sync_runs', 'login_attempts') as $t) {
            $tables['{' . $t . '}'] = self::table($t);
        }
        return strtr(strtr($sql, $map), $tables);
    }

    public static function run($sql, array $params = array())
    {
        $stmt = self::pdo()->prepare(self::q($sql));
        $stmt->execute($params);
        return $stmt;
    }

    public static function fetchAll($sql, array $params = array())
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function fetch($sql, array $params = array())
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function value($sql, array $params = array(), $default = null)
    {
        $row = self::run($sql, $params)->fetch(\PDO::FETCH_NUM);
        if ($row === false || !isset($row[0])) {
            return $default;
        }
        return $row[0];
    }

    public static function insert($table, array $data)
    {
        $cols = array_keys($data);
        $sql = 'INSERT INTO ' . self::table($table) . ' (' . implode(', ', $cols) . ') VALUES (:' . implode(', :', $cols) . ')';
        $params = array();
        foreach ($data as $k => $v) {
            $params[':' . $k] = $v;
        }
        self::run($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    public static function update($table, array $data, $where, array $whereParams = array())
    {
        $sets = array();
        $params = array();
        foreach ($data as $k => $v) {
            $sets[] = $k . ' = :s_' . $k;
            $params[':s_' . $k] = $v;
        }
        foreach ($whereParams as $k => $v) {
            $params[is_int($k) ? ':' . $k : ':' . ltrim($k, ':')] = $v;
        }
        $sql = 'UPDATE ' . self::table($table) . ' SET ' . implode(', ', $sets) . ' WHERE ' . $where;
        return self::run($sql, $params)->rowCount();
    }

    public static function delete($table, $where, array $whereParams = array())
    {
        $params = array();
        foreach ($whereParams as $k => $v) {
            $params[':' . ltrim((string) $k, ':')] = $v;
        }
        return self::run('DELETE FROM ' . self::table($table) . ' WHERE ' . $where, $params)->rowCount();
    }

    public static function transaction(callable $fn)
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function migrate()
    {
        if (self::$migrated) {
            return;
        }
        $pdo = self::pdo();
        $tables = array(
            'admins' => "CREATE TABLE IF NOT EXISTS {admins} (
                id {PK},
                username {STR} NOT NULL,
                password_hash {STR} NOT NULL,
                email {STR} NULL,
                display_name {STR} NULL,
                is_super {BOOL} DEFAULT 1,
                created_at {TS} NULL,
                last_login_at {TS} NULL,
                last_login_ip {STR} NULL,
                UNIQUE (username)
            ){ENGINE}",
            'settings' => "CREATE TABLE IF NOT EXISTS {settings} (
                skey {STR} NOT NULL,
                svalue {TEXT} NULL,
                updated_at {TS} NULL,
                UNIQUE (skey)
            ){ENGINE}",
            'servers' => "CREATE TABLE IF NOT EXISTS {servers} (
                id {PK},
                name {STR} NOT NULL,
                base_url {STR} NOT NULL,
                admin_url {STR} NULL,
                webmail_url {STR} NULL,
                api_path {STR} NULL,
                auth_mode {STR} NULL,
                api_token_enc {TEXT} NULL,
                api_token_hint {STR} NULL,
                api_user_enc {TEXT} NULL,
                api_pass_enc {TEXT} NULL,
                verify_tls {BOOL} DEFAULT 1,
                timeout {INT} DEFAULT 20,
                imap_host {STR} NULL, imap_port {INT} NULL, imap_security {STR} NULL,
                pop3_host {STR} NULL, pop3_port {INT} NULL, pop3_security {STR} NULL,
                smtp_host {STR} NULL, smtp_port {INT} NULL, smtp_security {STR} NULL,
                sieve_host {STR} NULL, sieve_port {INT} NULL,
                dkim_selector {STR} NULL,
                note {TEXT} NULL,
                enabled {BOOL} DEFAULT 1,
                created_at {TS} NULL,
                updated_at {TS} NULL,
                last_check_at {TS} NULL,
                last_check_ok {BOOL} NULL,
                last_check_msg {STR} NULL,
                cert_expires_at {TS} NULL,
                last_sync_at {TS} NULL
            ){ENGINE}",
            'domains' => "CREATE TABLE IF NOT EXISTS {domains} (
                id {PK},
                server_id {INT} NOT NULL,
                name {STR} NOT NULL,
                comment {TEXT} NULL,
                max_users {INT} NULL,
                max_aliases {INT} NULL,
                max_quota_bytes {BIGINT} NULL,
                signup_enabled {BOOL} NULL,
                alternatives {JSON} NULL,
                dns_json {JSON} NULL,
                remote_exists {BOOL} DEFAULT 1,
                created_at {TS} NULL,
                updated_at {TS} NULL,
                synced_at {TS} NULL,
                UNIQUE (server_id, name)
            ){ENGINE}",
            'users' => "CREATE TABLE IF NOT EXISTS {users} (
                id {PK},
                server_id {INT} NOT NULL,
                domain_id {INT} NULL,
                email {STR} NOT NULL,
                local_part {STR} NULL,
                domain_name {STR} NULL,
                displayed_name {STR} NULL,
                comment {TEXT} NULL,
                quota_bytes {BIGINT} NULL,
                quota_bytes_used {BIGINT} NULL,
                enabled {BOOL} DEFAULT 1,
                global_admin {BOOL} DEFAULT 0,
                enable_imap {BOOL} DEFAULT 1,
                enable_pop {BOOL} DEFAULT 1,
                forward_enabled {BOOL} DEFAULT 0,
                forward_destination {JSON} NULL,
                spam_enabled {BOOL} DEFAULT 1,
                spam_threshold {INT} NULL,
                password_enc {TEXT} NULL,
                password_set_at {TS} NULL,
                password_source {STR} NULL,
                remote_exists {BOOL} DEFAULT 1,
                created_at {TS} NULL,
                updated_at {TS} NULL,
                synced_at {TS} NULL,
                UNIQUE (server_id, email)
            ){ENGINE}",
            'aliases' => "CREATE TABLE IF NOT EXISTS {aliases} (
                id {PK},
                server_id {INT} NOT NULL,
                domain_id {INT} NULL,
                email {STR} NOT NULL,
                destination {JSON} NULL,
                comment {TEXT} NULL,
                remote_exists {BOOL} DEFAULT 1,
                created_at {TS} NULL,
                updated_at {TS} NULL,
                synced_at {TS} NULL,
                UNIQUE (server_id, email)
            ){ENGINE}",
            'audit_logs' => "CREATE TABLE IF NOT EXISTS {audit_logs} (
                id {PK},
                admin_id {INT} NULL,
                admin_name {STR} NULL,
                server_id {INT} NULL,
                action {STR} NULL,
                target_type {STR} NULL,
                target_label {STR} NULL,
                result {STR} NULL,
                message {TEXT} NULL,
                meta_json {JSON} NULL,
                ip {STR} NULL,
                created_at {TS} NULL
            ){ENGINE}",
            'deploy_scripts' => "CREATE TABLE IF NOT EXISTS {deploy_scripts} (
                id {PK},
                server_id {INT} NULL,
                name {STR} NULL,
                filename {STR} NULL,
                params_json {JSON} NULL,
                sha256 {STR} NULL,
                size {INT} NULL,
                content {TEXT} NULL,
                created_by {STR} NULL,
                created_at {TS} NULL
            ){ENGINE}",
            'deploy_jobs' => "CREATE TABLE IF NOT EXISTS {deploy_jobs} (
                id {PK},
                server_id {INT} NULL,
                script_id {INT} NULL,
                method {STR} NULL,
                target_host {STR} NULL,
                status {STR} NULL,
                exit_code {INT} NULL,
                log_file {STR} NULL,
                output_tail {TEXT} NULL,
                started_at {TS} NULL,
                finished_at {TS} NULL
            ){ENGINE}",
            'sync_runs' => "CREATE TABLE IF NOT EXISTS {sync_runs} (
                id {PK},
                server_id {INT} NULL,
                scope {STR} NULL,
                status {STR} NULL,
                stats_json {JSON} NULL,
                message {TEXT} NULL,
                started_at {TS} NULL,
                finished_at {TS} NULL
            ){ENGINE}",
            'login_attempts' => "CREATE TABLE IF NOT EXISTS {login_attempts} (
                id {PK},
                username {STR} NULL,
                ip {STR} NULL,
                success {BOOL} NULL,
                created_at {TS} NULL
            ){ENGINE}",
        );
        foreach ($tables as $name => $ddl) {
            $pdo->exec(self::q(strtr($ddl, array('{admins}' => self::table('admins'), '{settings}' => self::table('settings'), '{servers}' => self::table('servers'), '{domains}' => self::table('domains'), '{users}' => self::table('users'), '{aliases}' => self::table('aliases'), '{audit_logs}' => self::table('audit_logs'), '{deploy_scripts}' => self::table('deploy_scripts'), '{deploy_jobs}' => self::table('deploy_jobs'), '{sync_runs}' => self::table('sync_runs'), '{login_attempts}' => self::table('login_attempts')))));
        }
        $indexes = array(
            'idx_domains_server' => 'domains (server_id)',
            'idx_users_server_domain' => 'users (server_id, domain_id)',
            'idx_users_domain' => 'users (domain_name)',
            'idx_aliases_server' => 'aliases (server_id)',
            'idx_audit_created' => 'audit_logs (created_at)',
            'idx_attempt_created' => 'login_attempts (created_at)',
        );
        foreach ($indexes as $name => $cols) {
            $cols = str_replace(array('domains', 'users', 'aliases', 'audit_logs', 'login_attempts'), array(self::table('domains'), self::table('users'), self::table('aliases'), self::table('audit_logs'), self::table('login_attempts')), $cols);
            try {
                $pdo->exec('CREATE INDEX IF NOT EXISTS ' . $name . ' ON ' . $cols);
            } catch (\PDOException $e) {
                try {
                    $pdo->exec('CREATE INDEX ' . $name . ' ON ' . $cols);
                } catch (\PDOException $e2) {
                    // index already exists - ignore
                }
            }
        }
        self::$migrated = true;
        self::seedDefaults();
    }

    private static function seedDefaults()
    {
        $defaults = array(
            'app.installed_at' => date('Y-m-d H:i:s'),
        );
        foreach ($defaults as $k => $v) {
            if (self::value('SELECT COUNT(*) FROM {settings} WHERE skey = ?', array($k), 0) == 0) {
                self::insert('settings', array('skey' => $k, 'svalue' => $v, 'updated_at' => date('Y-m-d H:i:s')));
            }
        }
    }
}