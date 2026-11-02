<?php
/**
 * CSV import / export for domains, accounts and aliases.
 */

namespace App\Service;

use App\Core\Database;
use App\Core\Logger;

final class ImportExportService
{
    public static function csv(array $rows, array $headers = null)
    {
        $fh = fopen('php://temp', 'r+');
        if ($headers === null) {
            $headers = $rows ? array_keys($rows[0]) : array();
        }
        fputcsv($fh, $headers);
        foreach ($rows as $row) {
            $line = array();
            foreach ($headers as $header) {
                $value = isset($row[$header]) ? $row[$header] : '';
                if (is_array($value)) {
                    $value = implode(' ', $value);
                }
                $line[] = $value;
            }
            fputcsv($fh, $line);
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return "\xEF\xBB\xBF" . $csv;
    }

    /** Parse a CSV/TSV text into associative rows (header row required). */
    public static function parse($text)
    {
        $text = str_replace(array("\r\n", "\r"), "\n", (string) $text);
        if (substr($text, 0, 3) === "\xEF\xBB\xBF") {
            $text = substr($text, 3);
        }
        $lines = array_values(array_filter(explode("\n", $text), function ($line) {
            return trim($line) !== '';
        }));
        if (!$lines) {
            return array('headers' => array(), 'rows' => array());
        }
        $delimiter = self::detectDelimiter($lines[0]);
        $headers = array_map(function ($header) {
            return strtolower(trim(str_replace(array('"', "'"), '', $header)));
        }, explode($delimiter, array_shift($lines)));
        $rows = array();
        foreach ($lines as $line) {
            $values = str_getcsv($line, $delimiter);
            $row = array();
            foreach ($headers as $index => $header) {
                $row[$header] = isset($values[$index]) ? trim((string) $values[$index]) : '';
            }
            if (implode('', $row) !== '') {
                $rows[] = $row;
            }
        }
        return array('headers' => $headers, 'rows' => $rows);
    }

    private static function detectDelimiter($line)
    {
        $candidates = array(',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t"), '|' => substr_count($line, '|'));
        arsort($candidates);
        $keys = array_keys($candidates);
        return $candidates[$keys[0]] > 0 ? $keys[0] : ',';
    }

    /** Import accounts: expects columns email / password / quota_mb / name / comment. */
    public static function importUsers($serverId, $text, $dryRun = false)
    {
        $parsed = self::parse($text);
        $rows = $parsed['rows'];
        $results = array();
        $created = 0;
        $failed = 0;
        $server = ServerService::findOrFail($serverId);
        foreach ($rows as $index => $row) {
            $email = strtolower(trim(self::pick($row, array('email', 'mail', '邮箱', '账号', 'user'))));
            if ($email === '') {
                continue;
            }
            $password = self::pick($row, array('password', 'pass', '密码'));
            $quota = self::pick($row, array('quota_mb', 'quota', '配额'));
            $name = self::pick($row, array('name', 'displayed_name', 'display_name', '姓名', '显示名'));
            $comment = self::pick($row, array('comment', 'note', '备注'));
            $entry = array('row' => $index + 2, 'email' => $email, 'ok' => false, 'message' => '');
            if ($dryRun) {
                $entry['ok'] = true;
                $entry['message'] = '校验通过（未写入）';
                $results[] = $entry;
                continue;
            }
            try {
                $result = UserService::create($serverId, array(
                    'email' => $email,
                    'password' => $password,
                    'quota_mb' => $quota,
                    'displayed_name' => $name,
                    'comment' => $comment,
                    'enabled' => true,
                ));
                $entry['ok'] = true;
                $entry['message'] = '已创建';
                $entry['password'] = $result['password'];
                $created++;
            } catch (\Throwable $e) {
                $entry['message'] = $e->getMessage();
                $failed++;
                Logger::warn('csv import user failed', array('email' => $email, 'error' => $e->getMessage()));
            }
            $results[] = $entry;
        }
        AuditService::log('import.users', $failed > 0 ? 'partial' : 'ok', array('created' => $created, 'failed' => $failed, 'dry_run' => (bool) $dryRun), array(
            'server_id' => (int) $serverId, 'target_type' => 'user', 'target_label' => 'CSV 导入',
        ));
        return array('created' => $created, 'failed' => $failed, 'total' => count($rows), 'results' => $results, 'headers' => $parsed['headers'], 'server' => $server['name']);
    }

    /** Import aliases: columns email / destination / comment. */
    public static function importAliases($serverId, $text, $dryRun = false)
    {
        $parsed = self::parse($text);
        $results = array();
        $created = 0;
        $failed = 0;
        foreach ($parsed['rows'] as $index => $row) {
            $email = strtolower(trim(self::pick($row, array('email', 'alias', '别名'))));
            $destination = self::pick($row, array('destination', 'destinations', 'target', '目标', '转发到'));
            if ($email === '') {
                continue;
            }
            $entry = array('row' => $index + 2, 'email' => $email, 'ok' => false, 'message' => '');
            if ($dryRun) {
                $entry['ok'] = true;
                $entry['message'] = '校验通过（未写入）';
                $results[] = $entry;
                continue;
            }
            try {
                AliasService::create($serverId, array('email' => $email, 'destination' => $destination, 'comment' => self::pick($row, array('comment', 'note', '备注'))));
                $entry['ok'] = true;
                $entry['message'] = '已创建';
                $created++;
            } catch (\Throwable $e) {
                $entry['message'] = $e->getMessage();
                $failed++;
            }
            $results[] = $entry;
        }
        return array('created' => $created, 'failed' => $failed, 'total' => count($parsed['rows']), 'results' => $results);
    }

    private static function pick(array $row, array $keys, $default = '')
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && $row[$key] !== '') {
                return $row[$key];
            }
        }
        return $default;
    }
}