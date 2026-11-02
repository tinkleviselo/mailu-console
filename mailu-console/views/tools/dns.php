<div class="card">
    <h2>DNS 记录与收信检查</h2>
    <p class="muted small">收信需要 MX 记录指向邮件服务器；发信需要 SPF、DKIM、DMARC 三件套，否则很容易进垃圾箱。</p>
    <form method="get" action="<?= e(url('/tools/dns')) ?>" class="row">
        <div class="field">
            <label>服务器</label>
            <select name="server_id" onchange="this.form.submit()">
                <option value="">全部</option>
                <?php foreach ($servers as $server): ?>
                    <option value="<?= (int) $server['id'] ?>" <?= ($domain && (int) $domain['server_id'] === (int) $server['id']) ? 'selected' : '' ?>><?= e($server['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>域名</label>
            <select name="domain_id" onchange="this.form.submit()">
                <?php foreach ($domains as $item): ?>
                    <option value="<?= (int) $item['id'] ?>" <?= ($domain && (int) $domain['id'] === (int) $item['id']) ? 'selected' : '' ?>><?= e($item['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>附加记录</label>
            <div class="checkbox"><input type="checkbox" name="mta_sts" value="1" id="mta" <?= !empty($mtaSts) ? 'checked' : '' ?>><label for="mta" style="margin:0">包含 MTA-STS / TLS-RPT</label></div>
        </div>
        <div class="field" style="flex:0 0 auto"><label>&nbsp;</label><button class="btn" type="submit">显示并校验</button></div>
    </form>
</div>

<?php if ($domain && $server): ?>
    <?php if ($verify): ?>
        <div class="alert alert-<?= ($verify['ok'] === $verify['checked']) ? 'success' : 'warning' ?>">
            域名 <strong><?= e($domain['name']) ?></strong> DNS 校验：<?= (int) $verify['ok'] ?>/<?= (int) $verify['checked'] ?> 条正确
            <?= !empty($verify['message']) ? e($verify['message']) : '' ?>
        </div>
    <?php endif; ?>
    <div class="card">
        <h3><?= e($domain['name']) ?> 需要配置的记录</h3>
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
                        <td class="mono small" style="max-width:520px;word-break:break-all"><span class="copyable" data-copy="<?= e($record['value']) ?>" title="点击复制"><?= e(str_limit($record['value'], 100)) ?></span></td>
                        <td class="small muted"><?= e(isset($record['note']) ? $record['note'] : '') ?></td>
                        <td>
                            <?php if ($status): ?>
                                <?php if ($status['status'] === 'ok'): ?><span class="badge badge-ok">正确</span>
                                <?php elseif ($status['status'] === 'missing'): ?><span class="badge badge-bad">未找到</span>
                                <?php else: ?><span class="badge badge-warn">不匹配</span><?php endif; ?>
                                <?php if (!empty($status['actual'])): ?><div class="small muted">当前: <?= e(str_limit(implode(' | ', $status['actual']), 70)) ?></div><?php endif; ?>
                                <?php if (!empty($status['message'])): ?><div class="small muted"><?= e($status['message']) ?></div><?php endif; ?>
                            <?php else: ?><span class="badge badge-muted">未校验</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="btn-row mt">
            <a class="btn" href="<?= e(url('/domains/' . $domain['id'])) ?>">域名详情</a>
            <a class="btn" href="<?= e(url('/tools/probe')) ?>">收发登录测试</a>
        </div>
    </div>
<?php else: ?>
    <div class="card"><p class="muted">还没有可检查的域名，请先在“域名管理”里添加域名。</p></div>
<?php endif; ?>