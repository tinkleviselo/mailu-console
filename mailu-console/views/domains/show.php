<div class="card">
    <div class="flex-between">
        <h2><?= e($domain['name']) ?></h2>
        <div class="btn-row">
            <form method="post" action="<?= e(url('/domains/' . $domain['id'] . '/dkim')) ?>"><?= csrf_field() ?><button class="btn" type="submit" data-confirm="将让 Mailu 生成 DKIM/DMARC 密钥，确定继续？">生成 DKIM 密钥</button></form>
            <form method="post" action="<?= e(url('/domains/' . $domain['id'] . '/sync')) ?>"><?= csrf_field() ?><button class="btn" type="submit">刷新</button></form>
            <a class="btn" href="<?= e(url('/domains/' . $domain['id'] . '/edit')) ?>">编辑</a>
            <a class="btn btn-primary" href="<?= e(url('/users/create' . qs(array('server_id' => $domain['server_id'], 'domain' => $domain['name'])))) ?>">新建账号</a>
        </div>
    </div>
    <dl class="kv">
        <dt>所属服务器</dt><dd><a href="<?= e(url('/servers/' . $server['id'])) ?>"><?= e($server['name']) ?></a> <span class="mono small muted"><?= e($server['base_url']) ?></span></dd>
        <dt>账号 / 别名</dt><dd><?= count($users) ?> 个账号 · <?= count($aliases) ?> 个别名</dd>
        <dt>限制</dt><dd>最大账号 <?= ((int) $domain['max_users'] === -1) ? '不限' : (int) $domain['max_users'] ?> · 最大别名 <?= ((int) $domain['max_aliases'] === -1) ? '不限' : (int) $domain['max_aliases'] ?> · 配额上限 <?= ((int) $domain['max_quota_bytes'] === 0) ? '不限' : e(format_bytes($domain['max_quota_bytes'])) ?></dd>
        <dt>备注</dt><dd><?= e($domain['comment']) ?></dd>
        <dt>最近同步</dt><dd><?= e(human_time($domain['synced_at'])) ?></dd>
    </dl>
</div>

<div class="card">
    <div class="flex-between">
        <h3>DNS 记录（收信 / 发信认证必须配置）</h3>
        <form method="get" action="<?= e(url('/domains/' . $domain['id'])) ?>">
            <input type="hidden" name="dns" value="1">
            <button class="btn btn-sm" type="submit">立即校验 DNS</button>
        </form>
    </div>
    <?php if ($verify): ?>
        <div class="alert alert-<?= ($verify['ok'] === $verify['checked']) ? 'success' : 'warning' ?>">
            DNS 校验：<?= (int) $verify['ok'] ?>/<?= (int) $verify['checked'] ?> 条正确
            <?= !empty($verify['message']) ? e($verify['message']) : '' ?>
        </div>
    <?php endif; ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>类型</th><th>主机记录</th><th>记录值</th><th>说明</th><th>校验</th></tr></thead>
            <tbody>
            <?php foreach ($records as $record): ?>
                <?php
                $status = null;
                if ($verify && !empty($verify['items'])) {
                    foreach ($verify['items'] as $item) {
                        if ($item['type'] === strtoupper($record['type']) && $item['name'] === $record['name'] && $item['expected'] === (string) $record['value']) {
                            $status = $item;
                        }
                    }
                }
                ?>
                <tr>
                    <td><span class="pill"><?= e($record['type']) ?></span></td>
                    <td class="mono small"><?= e($record['name']) ?><?= (isset($record['priority']) && $record['priority'] !== null) ? ' <span class="muted">(优先级 ' . (int) $record['priority'] . ')</span>' : '' ?></td>
                    <td class="mono small" style="max-width:520px;word-break:break-all">
                        <span class="copyable" data-copy="<?= e($record['value']) ?>" title="点击复制"><?= e(str_limit($record['value'], 90)) ?></span>
                    </td>
                    <td class="small muted"><?= e(isset($record['note']) ? $record['note'] : '') ?></td>
                    <td>
                        <?php if ($status): ?>
                            <?php if ($status['status'] === 'ok'): ?><span class="badge badge-ok">正确</span>
                            <?php elseif ($status['status'] === 'missing'): ?><span class="badge badge-bad">未找到</span>
                            <?php else: ?><span class="badge badge-warn">不匹配</span><?php endif; ?>
                            <?php if (!empty($status['actual'])): ?><div class="small muted">当前: <?= e(str_limit(implode(' | ', $status['actual']), 60)) ?></div><?php endif; ?>
                        <?php else: ?>
                            <span class="badge badge-muted">未校验</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="small muted mt">提示：DKIM 需要先点击“生成 DKIM 密钥”，再把对应 TXT 记录添加到 DNS；SPF 与 DMARC 建议立即添加，否则发出的邮件容易被判为垃圾邮件。</p>
</div>

<div class="grid cols-2">
    <div class="card">
        <div class="flex-between">
            <h3>账号 (<?= count($users) ?>)</h3>
            <a class="btn btn-sm" href="<?= e(url('/users' . qs(array('server_id' => $server['id'], 'domain' => $domain['name'])))) ?>">在账号列表查看</a>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>账号</th><th>配额</th><th>已用</th><th>状态</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($users, 0, 20) as $user): ?>
                    <tr>
                        <td><a href="<?= e(url('/users/' . $user['id'])) ?>"><?= e($user['email']) ?></a></td>
                        <td><?= e(format_bytes($user['quota_bytes'])) ?></td>
                        <td><?= e(format_bytes($user['quota_bytes_used'])) ?></td>
                        <td><?= (int) $user['enabled'] === 1 ? '<span class="badge badge-ok">启用</span>' : '<span class="badge badge-bad">已禁用</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$users): ?><tr><td colspan="4" class="muted">还没有账号</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card">
        <div class="flex-between">
            <h3>别名 (<?= count($aliases) ?>)</h3>
            <a class="btn btn-sm" href="<?= e(url('/aliases/create' . qs(array('server_id' => $server['id'])))) ?>">新建别名</a>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>别名</th><th>转发到</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($aliases, 0, 20) as $alias): ?>
                    <tr>
                        <td class="mono small"><?= e($alias['email']) ?></td>
                        <td class="mono small"><?= e(implode(', ', json_field($alias['destination']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$aliases): ?><tr><td colspan="2" class="muted">还没有别名</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>