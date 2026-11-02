<div class="grid cols-4 mb">
    <div class="stat"><div class="label">邮件服务器</div><div class="value"><?= (int) $stats['servers'] ?></div><div class="small muted">连接正常 <?= (int) $stats['servers_ok'] ?> 台</div></div>
    <div class="stat"><div class="label">域名</div><div class="value"><?= (int) $stats['domains'] ?></div><div class="small muted">多域名逻辑已启用</div></div>
    <div class="stat"><div class="label">邮箱账号</div><div class="value"><?= (int) $stats['users'] ?></div><div class="small muted">启用中 <?= (int) $stats['users_enabled'] ?></div></div>
    <div class="stat"><div class="label">别名 / 转发</div><div class="value"><?= (int) $stats['aliases'] ?></div><div class="small muted">已保存密码 <?= (int) $stats['passwords_known'] ?></div></div>
</div>

<div class="grid cols-2">
    <div class="card">
        <h3>邮件服务器状态</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>名称</th><th>地址</th><th>域名/账号</th><th>状态</th><th>最近同步</th></tr></thead>
                <tbody>
                <?php if (!$servers): ?>
                    <tr><td colspan="5" class="muted">还没有邮件服务器：先在部署中心生成安装脚本，安装完成后到“邮件服务器 → 添加服务器”。</td></tr>
                <?php endif; ?>
                <?php foreach ($servers as $server): ?>
                    <tr>
                        <td><a href="<?= e(url('/servers/' . $server['id'])) ?>"><?= e($server['name']) ?></a></td>
                        <td class="mono small"><?= e($server['base_url']) ?></td>
                        <td><?= (int) $server['domain_count'] ?> / <?= (int) $server['user_count'] ?></td>
                        <td><?php if ($server['last_check_ok'] === null): ?><span class="badge badge-muted">未检查</span><?php else: ?><?= badge((int) $server['last_check_ok'] === 1, '正常', '异常') ?><?php endif; ?></td>
                        <td class="small muted"><?= e(human_time($server['last_sync_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>账号数量 Top 域名</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>域名</th><th>服务器</th><th>账号数</th></tr></thead>
                <tbody>
                <?php foreach ($domains as $domain): ?>
                    <tr>
                        <td><a href="<?= e(url('/domains/' . $domain['id'])) ?>"><?= e($domain['name']) ?></a></td>
                        <td class="small muted"><?= e($domain['server_name']) ?></td>
                        <td><?= (int) $domain['user_count'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$domains): ?><tr><td colspan="3" class="muted">暂无域名</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="grid cols-2">
    <div class="card">
        <h3>邮箱容量使用 Top</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>账号</th><th>已用</th><th>配额</th><th>使用率</th></tr></thead>
                <tbody>
                <?php foreach ($topUsage as $user): ?>
                    <?php $percent = ((int) $user['quota_bytes']) > 0 ? round(((int) $user['quota_bytes_used'] / (int) $user['quota_bytes']) * 100, 1) : 0; ?>
                    <tr>
                        <td><a href="<?= e(url('/users/' . $user['id'])) ?>"><?= e($user['email']) ?></a></td>
                        <td><?= e(format_bytes($user['quota_bytes_used'])) ?></td>
                        <td><?= e(format_bytes($user['quota_bytes'])) ?></td>
                        <td><?= e($percent) ?>%</td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$topUsage): ?><tr><td colspan="4" class="muted">暂无数据（同步服务器后显示）</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>最近同步</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>服务器</th><th>范围</th><th>结果</th><th>统计</th><th>时间</th></tr></thead>
                <tbody>
                <?php foreach ($recentSync as $run): ?>
                    <tr>
                        <td><?= e($run['server_name']) ?></td>
                        <td><?= e($run['scope']) ?></td>
                        <td><?= status_badge($run['status']) ?></td>
                        <td class="small mono"><?= e(str_limit(json_encode(json_field($run['stats_json']), JSON_UNESCAPED_UNICODE), 60)) ?></td>
                        <td class="small muted"><?= e(human_time($run['finished_at'] ? $run['finished_at'] : $run['started_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$recentSync): ?><tr><td colspan="5" class="muted">还没有同步记录</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="flex-between">
        <h3>最近操作</h3>
        <a class="btn btn-sm" href="<?= e(url('/audit')) ?>">查看全部审计</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>时间</th><th>操作者</th><th>动作</th><th>对象</th><th>结果</th><th>说明</th></tr></thead>
            <tbody>
            <?php foreach ($recentAudit as $log): ?>
                <tr>
                    <td class="small muted"><?= e($log['created_at']) ?></td>
                    <td><?= e($log['admin_name']) ?></td>
                    <td class="mono small"><?= e($log['action']) ?></td>
                    <td><?= e($log['target_label']) ?></td>
                    <td><?= status_badge($log['result']) ?></td>
                    <td class="small muted"><?= e(str_limit($log['message'], 60)) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$recentAudit): ?><tr><td colspan="6" class="muted">暂无操作记录</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>