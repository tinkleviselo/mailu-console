<?php

namespace App\Controller;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Service\AuditService;
use App\Service\CredentialService;
use App\Service\DnsService;
use App\Service\DomainService;
use App\Service\ImportExportService;
use App\Service\MailProbe;
use App\Service\ServerService;
use App\Service\UserService;

final class ToolController extends Controller
{
    public function dns()
    {
        $servers = ServerService::all();
        $domainId = Request::int('domain_id', 0);
        $serverId = Request::int('server_id', 0);
        $domain = $domainId > 0 ? DomainService::find($domainId) : null;
        if (!$domain && $serverId > 0) {
            $first = DomainService::all(array('server_id' => $serverId));
            $domain = $first ? $first[0] : null;
        }
        if (!$domain && $servers) {
            $all = DomainService::all();
            $domain = $all ? $all[0] : null;
        }
        $server = $domain ? ServerService::find($domain['server_id']) : null;
        $records = ($domain && $server) ? DomainService::expectedRecords($domain, $server, array('with_mta_sts' => Request::bool('mta_sts', false))) : array();
        $verify = null;
        if ($records && Request::bool('check', true)) {
            $verify = DnsService::verify($records);
        }
        $this->render('tools/dns', array(
            'title' => 'DNS 记录与收信检查',
            'servers' => $servers,
            'domains' => DomainService::all(array('server_id' => $serverId)),
            'domain' => $domain,
            'server' => $server,
            'records' => $records,
            'verify' => $verify,
            'mtaSts' => Request::bool('mta_sts', false),
        ));
    }

    public function dnsCheck()
    {
        $domainId = Request::int('domain_id', 0);
        try {
            $domain = DomainService::findOrFail($domainId);
            $server = ServerService::findOrFail($domain['server_id']);
            $records = DomainService::expectedRecords($domain, $server, array('with_mta_sts' => Request::bool('mta_sts', false)));
            $verify = DnsService::verify($records);
            AuditService::log('dns.check', 'ok', array('ok' => $verify['ok'], 'checked' => $verify['checked']), array(
                'server_id' => (int) $server['id'], 'target_type' => 'domain', 'target_label' => $domain['name'],
            ));
            $this->json(array('ok' => true, 'result' => $verify));
        } catch (\Throwable $e) {
            $this->json(array('ok' => false, 'error' => $e->getMessage()), 400);
        }
    }

    public function probe()
    {
        $this->render('tools/probe', array(
            'title' => '收发登录测试',
            'servers' => ServerService::all(),
            'results' => null,
            'tested' => null,
        ));
    }

    public function probeRun()
    {
        $serverId = Request::int('server_id', 0);
        $email = Request::str('email');
        $password = (string) Request::input('password', '');
        $useStored = Request::bool('use_stored', false);
        try {
            $server = ServerService::findOrFail($serverId);
            if ($email === '') {
                throw new \RuntimeException('请填写要测试的邮箱地址');
            }
            if ($password === '') {
                $user = UserService::findEmail($serverId, $email);
                if ($user && !empty($user['password_enc'])) {
                    $password = \App\Core\Crypto::decrypt($user['password_enc']);
                }
            }
            if ($password === '') {
                throw new \RuntimeException('缺少密码：请填写密码，或先在中台里为该账号设置过密码');
            }
            $tests = array();
            foreach (array('imap', 'smtp', 'pop3', 'sieve') as $test) {
                if (Request::bool('test_' . $test, in_array($test, array('imap', 'smtp'), true))) {
                    $tests[] = $test;
                }
            }
            if (!$tests) {
                throw new \RuntimeException('请至少选择一项测试内容');
            }
            $results = MailProbe::run($server, $email, $password, $tests);
            AuditService::log('mail.probe', 'ok', array('email' => $email, 'tests' => $tests), array(
                'server_id' => $serverId, 'target_type' => 'user', 'target_label' => $email,
            ));
            $this->render('tools/probe', array(
                'title' => '收发登录测试',
                'servers' => ServerService::all(),
                'results' => $results,
                'tested' => array('email' => $email, 'server' => $server['name'], 'use_stored' => $useStored),
            ));
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), '/tools/probe');
        }
    }

    public function credentials()
    {
        $filters = $this->filters();
        $reveal = Request::bool('reveal', false);
        $cards = CredentialService::collect($filters, $reveal, 500);
        $this->render('tools/credentials', array(
            'title' => '登录信息汇总',
            'filters' => $filters,
            'cards' => $cards,
            'reveal' => $reveal,
            'servers' => ServerService::all(),
            'domains' => DomainService::all(array('server_id' => $filters['server_id'])),
        ));
    }

    public function credentialsExport()
    {
        $filters = $this->filters();
        $format = Request::str('format', 'csv');
        $cards = CredentialService::collect($filters, true, 2000);
        AuditService::log('credentials.export', 'ok', array('rows' => count($cards), 'format' => $format));
        if ($format === 'txt') {
            $lines = array();
            foreach ($cards as $card) {
                $lines[] = str_repeat('=', 60);
                $lines[] = CredentialService::asText($card);
                $lines[] = '';
            }
            Response::download('mailu-login-info-' . date('Ymd-His') . '.txt', implode("\n", $lines), 'text/plain; charset=utf-8');
        }
        if ($format === 'html') {
            $html = '<html><head><meta charset="utf-8"><title>登录信息</title></head><body>';
            foreach ($cards as $card) {
                $html .= '<div style="page-break-after:always;border:1px solid #ccc;padding:16px;margin-bottom:16px;font-family:sans-serif">';
                $html .= '<h3>' . e($card['email']) . '</h3><pre>' . e(CredentialService::asText($card)) . '</pre></div>';
            }
            $html .= '</body></html>';
            Response::download('mailu-login-info-' . date('Ymd-His') . '.html', $html, 'text/html; charset=utf-8');
        }
        Response::download('mailu-login-info-' . date('Ymd-His') . '.csv', CredentialService::asCsv($cards), 'text/csv; charset=utf-8');
    }

    public function import()
    {
        $servers = ServerService::all(array('enabled' => 1));
        $this->render('tools/import', array(
            'title' => 'CSV 批量导入',
            'servers' => $servers,
            'result' => null,
            'mode' => Request::str('mode', 'users'),
        ));
    }

    public function importRun()
    {
        $serverId = Request::int('server_id', 0);
        $mode = Request::str('mode', 'users');
        $dryRun = Request::bool('dry_run', false);
        $text = (string) Request::input('csv_text', '');
        $uploaded = Request::uploadedText('csv_file');
        if ($uploaded !== null && $uploaded !== '') {
            $text = $uploaded;
        }
        if ($serverId <= 0 || trim($text) === '') {
            $this->fail('请选择服务器并粘贴或上传 CSV 内容', '/tools/import');
        }
        try {
            $result = $mode === 'aliases'
                ? ImportExportService::importAliases($serverId, $text, $dryRun)
                : ImportExportService::importUsers($serverId, $text, $dryRun);
            $this->render('tools/import', array(
                'title' => 'CSV 批量导入',
                'servers' => ServerService::all(array('enabled' => 1)),
                'result' => $result,
                'mode' => $mode,
            ));
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), '/tools/import');
        }
    }
}