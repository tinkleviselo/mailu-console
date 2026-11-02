<?php
/**
 * DNS record generation + live verification (MX / SPF / DKIM / DMARC).
 */

namespace App\Service;

use App\Core\Logger;

final class DnsService
{
    public static function available()
    {
        return function_exists('dns_get_record');
    }

    public static function lookupTxt($name)
    {
        $records = self::lookup($name, DNS_TXT);
        $values = array();
        foreach ($records as $record) {
            if (isset($record['entries']) && is_array($record['entries'])) {
                $values[] = implode('', $record['entries']);
            } elseif (isset($record['txt'])) {
                $values[] = $record['txt'];
            }
        }
        return array_values(array_unique($values));
    }

    public static function lookupMx($name)
    {
        $out = array();
        foreach (self::lookup($name, DNS_MX) as $record) {
            if (isset($record['target'])) {
                $out[] = array('target' => rtrim($record['target'], '.'), 'pri' => isset($record['pri']) ? (int) $record['pri'] : 0);
            }
        }
        usort($out, function ($a, $b) {
            return $a['pri'] - $b['pri'];
        });
        return $out;
    }

    public static function lookupA($name)
    {
        $out = array();
        foreach (self::lookup($name, DNS_A) as $record) {
            if (!empty($record['ip'])) {
                $out[] = $record['ip'];
            }
        }
        return $out;
    }

    public static function lookupCname($name)
    {
        $out = array();
        foreach (self::lookup($name, DNS_CNAME) as $record) {
            if (!empty($record['target'])) {
                $out[] = rtrim($record['target'], '.');
            }
        }
        return $out;
    }

    private static function lookup($name, $type)
    {
        if (!self::available()) {
            return array();
        }
        $records = @dns_get_record($name, $type);
        return is_array($records) ? $records : array();
    }

    /**
     * Verify the expected records against live DNS.
     * Returns ['checked'=>int,'ok'=>int,'items'=>[...]].
     */
    public static function verify(array $expected, array $options = array())
    {
        $result = array('checked' => 0, 'ok' => 0, 'items' => array(), 'available' => self::available());
        if (!self::available()) {
            $result['message'] = '当前 PHP 未启用 dns_get_record()，无法在线校验，请手动核对记录。';
            return $result;
        }
        foreach ($expected as $record) {
            $type = strtoupper($record['type']);
            if ($type === 'DNS') {
                continue;
            }
            $name = $record['name'];
            $value = (string) $record['value'];
            $item = array(
                'type' => $type,
                'name' => $name,
                'expected' => $value,
                'actual' => array(),
                'status' => 'unknown',
                'message' => '',
                'note' => isset($record['note']) ? $record['note'] : '',
                'priority' => isset($record['priority']) ? $record['priority'] : null,
            );
            $result['checked']++;
            try {
                if ($type === 'MX') {
                    $actual = self::lookupMx($name);
                    $targets = array();
                    foreach ($actual as $mx) {
                        $targets[] = $mx['target'];
                    }
                    $item['actual'] = $targets;
                    $expectedHost = rtrim(trim($value), '.');
                    $found = false;
                    foreach ($targets as $target) {
                        if (strcasecmp($target, $expectedHost) === 0) {
                            $found = true;
                        }
                    }
                    if ($found) {
                        $item['status'] = 'ok';
                    } elseif (!$targets) {
                        $item['status'] = 'missing';
                        $item['message'] = '未查询到 MX 记录，收信会失败';
                    } else {
                        $item['status'] = 'mismatch';
                        $item['message'] = 'MX 指向其他服务器（若已有其他邮件网关可忽略）';
                    }
                } else {
                    $actual = self::lookupTxt($name);
                    $item['actual'] = $actual;
                    $normalisedExpected = preg_replace('/\s+/', '', $value);
                    $found = false;
                    foreach ($actual as $txt) {
                        if (preg_replace('/\s+/', '', $txt) === $normalisedExpected) {
                            $found = true;
                        }
                    }
                    if ($found) {
                        $item['status'] = 'ok';
                    } elseif (!$actual) {
                        $item['status'] = 'missing';
                        $item['message'] = '未查询到 TXT 记录';
                    } else {
                        $item['status'] = 'mismatch';
                        $item['message'] = 'TXT 记录与期望值不同（若已有旧的 SPF/DKIM 记录，请替换）';
                    }
                }
            } catch (\Throwable $e) {
                $item['status'] = 'error';
                $item['message'] = $e->getMessage();
                Logger::warn('dns verify failed', array('name' => $name, 'error' => $e->getMessage()));
            }
            if ($item['status'] === 'ok') {
                $result['ok']++;
            }
            $result['items'][] = $item;
        }
        return $result;
    }
}