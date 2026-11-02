<?php
/**
 * Mailu Console configuration example.
 * Copy to config/config.php (the setup wizard does this for you) and adjust.
 */

return array(

    'app' => array(
        'name' => 'Mailu 邮件中台',
        'version' => '1.0.0',
        // 32+ random characters. The setup wizard generates one.
        'key' => 'please-change-this-to-a-random-32-chars-string',
        'debug' => false,
        // Set when the console is served from a sub directory, e.g. '/console'
        'base_path' => '',
        'timezone' => 'Asia/Shanghai',
    ),

    'db' => array(
        // sqlite (default, zero configuration) or mysql
        'driver' => 'sqlite',
        // sqlite: file path (relative paths live in storage/)
        'database' => 'mailu.sqlite',
        // mysql only:
        'host' => '127.0.0.1',
        'port' => 3306,
        'username' => 'mailu_console',
        'password' => '',
        'prefix' => 'mu_',
    ),

    'session' => array(
        'name' => 'mailu_console_sid',
        'lifetime' => 120,
    ),

    'mailu' => array(
        'api_path' => '/api/v1',
        'timeout' => 20,
        'default_quota_mb' => 2048,
        'default_password_length' => 16,
        'sync_user_limit' => 2000,
    ),

    'deploy' => array(
        // Mailu release the generated scripts install by default
        'mailu_version' => '2024.06.61',
        // Optional download accelerator, e.g. 'https://ghproxy.net/'
        'mirror_prefix' => '',
        // Allow the console to push + run a generated script over SSH
        'allow_remote_deploy' => false,
        'ssh_binary' => 'ssh',
        'scp_binary' => 'scp',
    ),

    'security' => array(
        'allow_api' => true,
        'password_min_length' => 10,
    ),
);