<?php
/**
 * Builds the "mail login information" the operator hands to end users:
 * IMAP / POP3 / SMTP endpoints, webmail URL, autodiscover hints and export
 * formats (CSV / TXT / printable HTML / Mozilla autoconfig XML).
 */

namespace App\Service;

use App\Core\Crypto;

final class CredentialService
{
    /** Normalised connection endpoints for one mail server. */
    public static function endpoints(array $server)
    {
        $host = parse_url($server['base_url'], PHP_URL_HOST);
        $host = $host ? $host : '';
        $pick = function ($value, $fallback) {
            return ($value === null || $value === '') ? $fallback : $value;
        };
        $imapHost = $pick(isset($server['imap_host']) ? $server['imap_host'] : '', $host);
        $smtpHost = $pick(isset($server['smtp_host']) ? $server['smtp_host'] : '', $host);
        $pop3Host = $pick(isset($server['pop3_host']) ? $server['pop3_host'] : '', $host);
        $webmail = $pick(isset($server['webmail_url']) ? $server['webmail_url'] : '', $host ? 'https://' . $host . '/webmail' : '');
        return array(
            'host' => $host,
            'imap' => array(
                'host' => $imapHost,
                'port' => (int) $pick(isset($server['imap_port']) ? $server['imap_port'] : '', 993),
                'security' => $pick(isset($server['imap_security']) ? $server['imap_security'] : '', 'ssl'),
            ),
            'pop3' => array(
                'host' => $pop3Host,
                'port' => (int) $pick(isset($server['pop3_port']) ? $server['pop3_port'] : '', 995),
                'security' => $pick(isset($server['pop3_security']) ? $server['pop3_security'] : '', 'ssl'),
            ),
            'smtp' => array(
                'host' => $smtpHost,
                'port' => (int) $pick(isset($server['smtp_port']) ? $server['smtp_port'] : '', 465),
                'security' => $pick(isset($server['smtp_security']) ? $server['smtp_security'] : '', 'ssl'),
                'alt_port' => 587,
                'alt_security' => 'starttls',
            ),
            'sieve' => array(
                'host' => $pick(isset($server['sieve_host']) ? $server['sieve_host'] : '', $host),
                'port' => (int) $pick(isset($server['sieve_port']) ? $server['sieve_port'] : '', 4190),
            ),
            'webmail' => $webmail,
            'admin' => $pick(isset($server['admin_url']) ? $server['admin_url'] : '', $host ? 'https://' . $host . '/admin' : ''),
        );
    }

    /** Full login sheet for a single mailbox. */
    public static function forUser(array $user, array $server, $revealPassword = true)
    {
        $password = '';
        if (!empty($user['password_enc'])) {
            $password = Crypto::decrypt($user['password_enc']);
        }
        if (!$revealPassword) {
            $password = $password === '' ? '' : str_repeat('*', max(8, strlen($password)));
        }
        return array(
            'email' => $user['email'],
            'displayed_name' => isset($user['displayed_name']) ? $user['displayed_name'] : '',
            'password' => $password,
            'password_known' => $password !== '',
            'quota_mb' => isset($user['quota_bytes']) ? round(((int) $user['quota_bytes']) / 1048576, 2) : 0,
            'used_mb' => isset($user['quota_bytes_used']) ? round(((int) $user['quota_bytes_used']) / 1048576, 2) : 0,
            'enabled' => isset($user['enabled']) ? (int) $user['enabled'] === 1 : true,
            'server' => $server['name'],
            'endpoints' => self::endpoints($server),
        );
    }

    /** Plain text block (paste into a mail / chat message). */
    public static function asText(array $card)
    {
        $ep = $card['endpoints'];
        $lines = array();
        $lines[] = '邮箱账号: ' . $card['email'];
        $lines[] = '登录密码: ' . ($card['password'] !== '' ? $card['password'] : '（请向管理员索取）');
        $lines[] = '网页邮箱: ' . $ep['webmail'];
        $lines[] = '';
        $lines[] = '收信服务器 (IMAP): ' . $ep['imap']['host'] . '  端口 ' . $ep['imap']['port'] . '  加密 ' . strtoupper($ep['imap']['security']);
        $lines[] = '收信服务器 (POP3): ' . $ep['pop3']['host'] . '  端口 ' . $ep['pop3']['port'] . '  加密 ' . strtoupper($ep['pop3']['security']);
        $lines[] = '发信服务器 (SMTP): ' . $ep['smtp']['host'] . '  端口 ' . $ep['smtp']['port'] . '  加密 ' . strtoupper($ep['smtp']['security']);
        if (!empty($ep['smtp']['alt_port'])) {
            $lines[] = '发信备用端口: ' . $ep['smtp']['alt_port'] . ' (STARTTLS)';
        }
        $lines[] = '账号需要填写完整邮箱地址；IMAP/POP3/SMTP 均使用同一密码。';
        return implode("\n", $lines);
    }

    /** CSV export of many mailboxes. */
    public static function asCsv(array $cards)
    {
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, array('邮箱账号', '密码', '显示名称', '服务器', '网页邮箱', 'IMAP主机', 'IMAP端口', 'IMAP加密', 'POP3主机', 'POP3端口', 'SMTP主机', 'SMTP端口', 'SMTP加密', '配额MB', '已用MB', '状态'));
        foreach ($cards as $card) {
            $ep = $card['endpoints'];
            fputcsv($fh, array(
                $card['email'],
                $card['password'] !== '' ? $card['password'] : '',
                $card['displayed_name'],
                $card['server'],
                $ep['webmail'],
                $ep['imap']['host'], $ep['imap']['port'], $ep['imap']['security'],
                $ep['pop3']['host'], $ep['pop3']['port'],
                $ep['smtp']['host'], $ep['smtp']['port'], $ep['smtp']['security'],
                $card['quota_mb'], $card['used_mb'],
                $card['enabled'] ? 'enabled' : 'disabled',
            ));
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        // Excel friendly BOM
        return "\xEF\xBB\xBF" . $csv;
    }

    /** Mozilla Thunderbird autoconfig XML (served from autoconfig.<domain>). */
    public static function autoconfigXml($domain, array $server)
    {
        $ep = self::endpoints($server);
        $imapSocket = strtoupper($ep['imap']['security']) === 'ssl' ? 'SSL' : 'STARTTLS';
        $smtpSocket = strtoupper($ep['smtp']['security']) === 'ssl' ? 'SSL' : 'STARTTLS';
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<clientConfig version="1.1">' . "\n";
        $xml .= '  <emailProvider id="' . htmlspecialchars($domain, ENT_XML1) . '">' . "\n";
        $xml .= '    <domain>' . htmlspecialchars($domain, ENT_XML1) . '</domain>' . "\n";
        $xml .= '    <displayName>' . htmlspecialchars($domain, ENT_XML1) . ' Mail</displayName>' . "\n";
        $xml .= '    <displayShortName>' . htmlspecialchars($domain, ENT_XML1) . '</displayShortName>' . "\n";
        $xml .= '    <incomingServer type="imap">' . "\n";
        $xml .= '      <hostname>' . htmlspecialchars($ep['imap']['host'], ENT_XML1) . '</hostname>' . "\n";
        $xml .= '      <port>' . (int) $ep['imap']['port'] . '</port>' . "\n";
        $xml .= '      <socketType>' . $imapSocket . '</socketType>' . "\n";
        $xml .= '      <authentication>password-cleartext</authentication>' . "\n";
        $xml .= '      <username>%EMAILADDRESS%</username>' . "\n";
        $xml .= '    </incomingServer>' . "\n";
        $xml .= '    <outgoingServer type="smtp">' . "\n";
        $xml .= '      <hostname>' . htmlspecialchars($ep['smtp']['host'], ENT_XML1) . '</hostname>' . "\n";
        $xml .= '      <port>' . (int) $ep['smtp']['port'] . '</port>' . "\n";
        $xml .= '      <socketType>' . $smtpSocket . '</socketType>' . "\n";
        $xml .= '      <authentication>password-cleartext</authentication>' . "\n";
        $xml .= '      <username>%EMAILADDRESS%</username>' . "\n";
        $xml .= '    </outgoingServer>' . "\n";
        $xml .= '  </emailProvider>' . "\n";
        $xml .= '</clientConfig>' . "\n";
        return $xml;
    }

    /** Collect login sheets for a filtered set of mailboxes. */
    public static function collect(array $filters = array(), $revealPassword = true, $limit = 2000)
    {
        $users = UserService::all($filters);
        $cards = array();
        $servers = array();
        $count = 0;
        foreach ($users as $user) {
            if ($count >= $limit) {
                break;
            }
            $serverId = (int) $user['server_id'];
            if (!isset($servers[$serverId])) {
                $servers[$serverId] = ServerService::find($serverId);
            }
            if (!$servers[$serverId]) {
                continue;
            }
            $cards[] = self::forUser($user, $servers[$serverId], $revealPassword);
            $count++;
        }
        return $cards;
    }
}