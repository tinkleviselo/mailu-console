<div class="card">
    <div class="flex-between">
        <h2>邮件服务器 (Mailu 实例)</h2>
        <div class="btn-row">
            <a class="btn" href="<?= e(url('/deploy')) ?>">部署中心</a>
            <a class="btn btn-primary" href="<?= e(url('/servers/create')) ?>">添加服务器</a>
        </div>
    </div>
    <p class="muted small">中台通过 Mailu REST API 管理多台邮件服务器；每台服务器可以承载多个域名与账号。</p>
    <div class="table-wrap">
        <table>
            <thead><tr><th>名称</th><th>访问地址</th><th>API 凭据</th><th>域名</th><th>账号</th><th>别名</th><th>状态</th><th>最近同步</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($servers as $server): ?>
                <tr>
                    <td><a href="<?= e(url('/servers/' . $server['id'])) ?>"><?= e($server['name']) ?></a><br><span class="small muted"><?= e(str_limit($server['note'], 40)) ?></span></td>
                    <td class="mono small"><a href="<?= e($server['base_url']) ?>" target="_blank" rel="noopener"><?= e($server['base_url']) ?></a></td>
                    <td class="small mono"><?= e($server['api_token_hint'] ? $server['api_token_hint'] : ($server['auth_mode'] === 'basic' ? 'Basic Auth' : '未设置')) ?></td>
                    <td><?= (int) $server['domain_count'] ?></td>
                    <td><?= (int) $server['user_count'] ?></td>
                    <td><?= (int) $server['alias_count'] ?></td>
                    <td>
                        <?php if ($server['last_check_ok'] === null): ?><span class="badge badge-muted">未检查</span><?php else: ?><?= badge((int) $server['last_check_ok'] === 1, '正常', '异常') ?><?php endif; ?>
                        <?php if (!empty($server['cert_expires_at'])): ?><div class="small muted">证书至 <?= e(date('Y-m-d', strtotime($server['cert_expires_at']))) ?></div><?php endif; ?>
                    </td>
                    <td class="small muted"><?= e(human_time($server['last_sync_at'])) ?></td>
                    <td>
                        <div class="btn-row">
                            <form method="post" action="<?= e(url('/servers/' . $server['id'] . '/check')) ?>"><?= csrf_field() ?><button class="btn btn-sm" type="submit">检查</button></form>
                            <form method="post" action="<?= e(url('/servers/' . $server['id'] . '/sync')) ?>"><?= csrf_field() ?><button class="btn btn-sm" type="submit">同步</button></form>
                            <a class="btn btn-sm" href="<?= e(url('/servers/' . $server['id'] . '/edit')) ?>">编辑</a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$servers): ?><tr><td colspan="9" class="muted">暂无服务器</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>