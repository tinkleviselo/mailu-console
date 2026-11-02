<?php
/**
 * Optional: push a generated script to a VPS over SSH and execute it.
 * Disabled unless config('deploy.allow_remote_deploy') is true and the ssh
 * binary (plus key based authentication) is available.
 */

namespace App\Service;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;

final class RemoteDeploy
{
    public static function available()
    {
        if (!Config::get('deploy.allow_remote_deploy', false)) {
            return false;
        }
        if (!function_exists('proc_open')) {
            return false;
        }
        return self::binary((string) Config::get('deploy.ssh_binary', 'ssh')) !== '';
    }

    public static function status()
    {
        $ssh = self::binary((string) Config::get('deploy.ssh_binary', 'ssh'));
        return array(
            'allowed' => (bool) Config::get('deploy.allow_remote_deploy', false),
            'proc_open' => function_exists('proc_open'),
            'ssh' => $ssh,
            'ready' => self::available(),
        );
    }

    private static function binary($name)
    {
        if (strpbrk($name, '/\\') !== false) {
            return is_file($name) ? $name : '';
        }
        $paths = explode(PATH_SEPARATOR, (string) getenv('PATH'));
        foreach ($paths as $path) {
            $candidate = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . $name;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return '';
    }

    /**
     * Upload (via stdin) and run a stored deploy script.
     * Options: host, port, user, sudo (bool), extra_args (string).
     */
    public static function run($scriptId, array $options = array())
    {
        $script = DeployScriptService::find($scriptId);
        if (!$script) {
            throw new \RuntimeException('部署脚本不存在');
        }
        if (!self::available()) {
            throw new \RuntimeException('远程执行未启用：请在 config/config.php 中打开 deploy.allow_remote_deploy，并确保服务器上存在 ssh 与免密钥登录。');
        }
        $host = trim((string) (isset($options['host']) ? $options['host'] : ''));
        if ($host === '') {
            throw new \RuntimeException('缺少目标主机 (host)');
        }
        $user = trim((string) (isset($options['user']) ? $options['user'] : 'root'));
        $port = (int) (isset($options['port']) ? $options['port'] : 22);
        if ($port <= 0) {
            $port = 22;
        }
        $sudo = !empty($options['sudo']);
        $ssh = self::binary((string) Config::get('deploy.ssh_binary', 'ssh'));
        $remotePath = '/tmp/' . preg_replace('/[^A-Za-z0-9._\-]/', '_', $script['filename']);
        $localPath = STORAGE_PATH . '/deploy/' . $script['filename'];
        if (!is_file($localPath)) {
            $localPath = STORAGE_PATH . '/cache/' . $script['filename'];
            @file_put_contents($localPath, $script['content'], LOCK_EX);
        }
        $remoteCommand = 'cat > ' . $remotePath . ' && chmod +x ' . $remotePath . ' && '
            . ($sudo ? 'sudo -n ' : '') . 'bash ' . $remotePath . ' --yes --non-interactive';
        $args = array($ssh, '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=accept-new', '-o', 'ConnectTimeout=15', '-p', (string) $port, $user . '@' . $host, $remoteCommand);
        $command = implode(' ', array_map(function ($part) {
            return escapeshellarg($part);
        }, $args));

        $logDir = STORAGE_PATH . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0750, true);
        }
        $logFile = $logDir . '/deploy-' . date('Ymd-His') . '-' . (int) $scriptId . '.log';
        $jobId = Database::insert('deploy_jobs', array(
            'server_id' => isset($script['server_id']) ? (int) $script['server_id'] : null,
            'script_id' => (int) $scriptId,
            'method' => 'ssh',
            'target_host' => $user . '@' . $host . ':' . $port,
            'status' => 'running',
            'log_file' => basename($logFile),
            'started_at' => date('Y-m-d H:i:s'),
        ));
        @file_put_contents($logFile, '[console] ' . date('c') . ' running: ' . $command . "\n\n", FILE_APPEND);
        $descriptors = array(
            0 => array('file', $localPath, 'r'),
            1 => array('file', $logFile, 'a'),
            2 => array('file', $logFile, 'a'),
        );
        $process = proc_open($command, $descriptors, $pipes);
        $exitCode = -1;
        if (is_resource($process)) {
            $exitCode = proc_close($process);
        } else {
            Logger::error('proc_open failed for remote deploy', array('command' => $command));
        }
        $output = is_file($logFile) ? (string) file_get_contents($logFile) : '';
        $tail = implode("\n", array_slice(preg_split('/\r?\n/', $output), -80));
        Database::update('deploy_jobs', array(
            'status' => $exitCode === 0 ? 'success' : 'failed',
            'exit_code' => $exitCode,
            'output_tail' => $tail,
            'finished_at' => date('Y-m-d H:i:s'),
        ), 'id = :id', array('id' => $jobId));
        AuditService::log('deploy.remote_run', $exitCode === 0 ? 'ok' : 'failed', array('host' => $host, 'exit_code' => $exitCode), array(
            'server_id' => isset($script['server_id']) ? (int) $script['server_id'] : null,
            'target_type' => 'deploy',
            'target_label' => $script['filename'],
            'message' => 'exit=' . $exitCode,
        ));
        return array('job_id' => $jobId, 'exit_code' => $exitCode, 'log_file' => basename($logFile), 'output' => $tail);
    }

    public static function jobs($limit = 30)
    {
        return Database::fetchAll(
            'SELECT j.*, s.name AS server_name FROM {deploy_jobs} j LEFT JOIN {servers} s ON s.id = j.server_id ORDER BY j.id DESC LIMIT ' . (int) $limit
        );
    }

    public static function findJob($id)
    {
        return Database::fetch('SELECT * FROM {deploy_jobs} WHERE id = ?', array((int) $id));
    }

    public static function jobLog(array $job)
    {
        $file = STORAGE_PATH . '/logs/' . basename((string) $job['log_file']);
        return is_file($file) ? (string) file_get_contents($file) : (string) $job['output_tail'];
    }
}