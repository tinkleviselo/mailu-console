<div class="card">
    <div class="flex-between">
        <h2><?= e($server['name']) ?></h2>
        <div class="btn-row">
            <form method="post" action="<?= e(url('/servers/' . $server['id'] . '/check')) ?>"><?= csrf_field() ?><button class="btn" type="submit">检查连接</button></form>
            <form method="post" action="<?= e(url('/servers/' . $server['id'] . '/sync')) ?>"><?= csrf_field() ?><button class="btn btn-primary" type="submit">同步域名/账号/别名</button></form>
            <a class="btn" href="<?= e(url('/servers/' . $server['id'] . '/edit')) ?>">编辑</a>
        </div>
    </div>
    <?php if ($check): ?>
        <div class="alert alert-<?= $check['ok'] ? 'success' : 'error' ?>">
            <strong><?= $check['ok'] ? '连接正常' : '连接失败' ?></strong>：<?= e($check['message']) ?>
            <?php if (!empty($check['cert'])): ?>
                <div class="small">证书 CN=<?= e($check['cert']['subject']) ?>，颁发者 <?= e($check['cert']['issuer']) ?>，剩余 <?= (int) $check['cert']['days_left'] ?> 天</div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <dl class="kv">
        <dt>访问地址</dt><dd class="mono"><a href="<?= e($server['base_url']) ?>" target="_blank" rel="noopener"><?= e($server['base_url']) ?></a></dd>
        <dt>API 地址</dt><dd class="mono"><?= e($server['base_url'] . $server['api_path']) ?></dd>
        <dt>管理后台</dt><dd class="mono"><a href="<?= e($server['admin_url']) ?>" target="_blank" rel="noopener"><?= e($server['admin_url']) ?></a></dd>
        <dt>网页邮箱</dt><dd class="mono"><a href="<?= e($server['webmail_url']) ?>" target="_blank" rel="noopener"><?= e($server['webmail_url']) ?></a></dd>
        <dt>认证方式</dt><dd><?= e($server['auth_mode'] === 'basic' ? 'HTTP Basic (管理员账号)' : 'API 令牌 (Bearer)') ?>，令牌 <?= e($server['api_token_hint'] ? $server['api_token_hint'] : '—') ?></dd>
        <dt>状态</dt>
        <dd>
            <?php if ($server['last_check_ok'] === null): ?><span class="badge badge-muted">未检查</span><?php else: ?><?= badge((int) $server['last_check_ok'] === 1, '正常', '异常') ?><?php endif; ?>
            <span class="small muted">最近检查 <?= e(human_time($server['last_check_at'])) ?> · <?= e($server['last_check_msg']) ?></span>
        </dd>
        <dt>账号 / 别名</dt><dd><?= (int) $stats['users'] ?> 个账号 · <?= (int) $stats['aliases'] ?> 个别名 · 配额合计 <?= e(format_bytes($stats['quota'])) ?>（已用 <?= e(format_bytes($stats['used'])) ?>）</dd>
        <dt>收发服务器</dt>
        <dd class="small mono">IMAP <?= e($endpoints['imap']['host']) ?>:<?= (int) $endpoints['imap']['port'] ?> (<?= e(strtoupper($endpoints['imap']['security'])) ?>) · SMTP <?= e($endpoints['smtp']['host']) ?>:<?= (int) $endpoints['smtp']['port'] ?> (<?= e(strtoupper($endpoints['smtp']['security'])) ?>) · POP3 <?= e($endpoints['pop3']['host']) ?>:<?= (int) $endpoints['pop3']['port'] ?></dd>
        <dt>最近同步</dt><dd><?= e(human_time($server['last_sync_at'])) ?></dd>
        <dt>备注</dt><dd><?= e($server['note']) ?></dd>
    </dl>
</div>

<div class="card">
    <div class="flex-between">
        <h3>域名 (<?= count($domains) ?>)</h3>
        <a class="btn btn-sm btn-primary" href="<?= e(url('/domains/create' . qs(array('server_id' => $server['id'])))) ?>">添加域名</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>域名</th><th>账号数</th><th>备注</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($domains as $domain): ?>
                <tr>
                    <td><a href="<?= e(url('/domains/' . $domain['id'])) ?>"><?= e($domain['name']) ?></a></td>
                    <td><?= (int) \App\Core\Database::value('SELECT COUNT(*) FROM {users} WHERE server_id = ? AND domain_name = ?', array((int) $server['id'], $domain['name']), 0) ?></td>
                    <td class="small muted"><?= e(str_limit($domain['comment'], 40)) ?></td>
                    <td class="btn-row">
                        <a class="btn btn-sm" href="<?= e(url('/domains/' . $domain['id'])) ?>">详情</a>
                        <a class="btn btn-sm" href="<?= e(url('/users/create' . qs(array('server_id' => $server['id'], 'domain' => $domain['name'])))) ?>">新建账号</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$domains): ?><tr><td colspan="4" class="muted">该服务器上还没有域名，先添加域名再创建账号。</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="grid cols-2">
    <div class="card">
        <h3>同步历史</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>时间</th><th>范围</th><th>结果</th><th>统计</th></tr></thead>
                <tbody>
                <?php foreach ($syncRuns as $run): ?>
                    <tr>
                        <td class="small muted"><?= e($run['started_at']) ?></td>
                        <td><?= e($run['scope']) ?></td>
                        <td><?= status_badge($run['status']) ?></td>
                        <td class="small mono"><?= e(json_encode(json_field($run['stats_json']), JSON_UNESCAPED_UNICODE)) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$syncRuns): ?><tr><td colspan="4" class="muted">暂无记录</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card">
        <h3>相关操作记录</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>时间</th><th>动作</th><th>对象</th><th>结果</th></tr></thead>
                <tbody>
                <?php foreach ($audit as $log): ?>
                    <tr>
                        <td class="small muted"><?= e($log['created_at']) ?></td>
                        <td class="mono small"><?= e($log['action']) ?></td>
                        <td><?= e($log['target_label']) ?></td>
                        <td><?= status_badge($log['result']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$audit): ?><tr><td colspan="4" class="muted">暂无记录</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>