<div class="card">
    <div class="flex-between">
        <h2>域名管理</h2>
        <a class="btn btn-primary" href="<?= e(url('/domains/create')) ?>">添加域名</a>
    </div>
    <form method="get" action="<?= e(url('/domains')) ?>" class="row">
        <div class="field">
            <label>所属服务器</label>
            <select name="server_id">
                <option value="">全部</option>
                <?php foreach ($servers as $server): ?>
                    <option value="<?= (int) $server['id'] ?>" <?= (int) $filters['server_id'] === (int) $server['id'] ? 'selected' : '' ?>><?= e($server['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field"><label>搜索</label><input type="text" name="q" value="<?= e($filters['q']) ?>" placeholder="域名或备注"></div>
        <div class="field" style="flex:0 0 auto"><label>&nbsp;</label><button class="btn" type="submit">筛选</button></div>
    </form>
    <div class="table-wrap">
        <table>
            <thead><tr><th>域名</th><th>服务器</th><th>账号</th><th>别名</th><th>MX</th><th>SPF</th><th>DKIM</th><th>服务器状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($result['items'] as $domain): ?>
                <?php $dns = isset($domain['dns']) ? $domain['dns'] : array(); ?>
                <tr>
                    <td><a href="<?= e(url('/domains/' . $domain['id'])) ?>"><?= e($domain['name']) ?></a></td>
                    <td class="small muted"><?= e($domain['server_name']) ?></td>
                    <td><?= (int) $domain['user_count'] ?></td>
                    <td><?= (int) $domain['alias_count'] ?></td>
                    <td><?= !empty($dns['mx']) ? '<span class="badge badge-ok">已获取</span>' : '<span class="badge badge-muted">—</span>' ?></td>
                    <td><?= !empty($dns['spf']) ? '<span class="badge badge-ok">已获取</span>' : '<span class="badge badge-muted">—</span>' ?></td>
                    <td><?= !empty($dns['dkim']) ? '<span class="badge badge-ok">已生成</span>' : '<span class="badge badge-warn">未生成</span>' ?></td>
                    <td><?= (int) $domain['remote_exists'] === 1 ? '<span class="badge badge-ok">存在</span>' : '<span class="badge badge-bad">服务器上已删除</span>' ?></td>
                    <td>
                        <div class="btn-row">
                            <a class="btn btn-sm" href="<?= e(url('/domains/' . $domain['id'])) ?>">详情</a>
                            <a class="btn btn-sm" href="<?= e(url('/users/create' . qs(array('server_id' => $domain['server_id'], 'domain' => $domain['name'])))) ?>">建账号</a>
                            <form method="post" action="<?= e(url('/domains/' . $domain['id'] . '/delete')) ?>">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-danger" type="submit" data-confirm="删除域名会同时删除该域名下的所有账号，确定继续吗？">删除</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$result['items']): ?>
                <tr><td colspan="9" class="muted">没有域名。可以先在部署中心安装邮件服务器，或直接添加域名（会自动创建到 Mailu 上）。</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php partial('partials/pagination'); ?>
</div>