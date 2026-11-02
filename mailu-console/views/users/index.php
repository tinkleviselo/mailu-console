<div class="card">
    <div class="flex-between">
        <h2>邮箱账号</h2>
        <div class="btn-row">
            <a class="btn" href="<?= e(url('/users/export' . qs())) ?>">导出 CSV</a>
            <a class="btn" href="<?= e(url('/users/bulk')) ?>">批量创建</a>
            <a class="btn btn-primary" href="<?= e(url('/users/create')) ?>">新建账号</a>
        </div>
    </div>
    <form method="get" action="<?= e(url('/users')) ?>" class="row">
        <div class="field">
            <label>服务器</label>
            <select name="server_id">
                <option value="">全部</option>
                <?php foreach ($servers as $server): ?>
                    <option value="<?= (int) $server['id'] ?>" <?= (int) $filters['server_id'] === (int) $server['id'] ? 'selected' : '' ?>><?= e($server['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>域名</label>
            <select name="domain">
                <option value="">全部</option>
                <?php foreach ($domains as $domain): ?>
                    <option value="<?= e($domain['name']) ?>" <?= $filters['domain'] === $domain['name'] ? 'selected' : '' ?>><?= e($domain['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field"><label>搜索</label><input type="text" name="q" value="<?= e($filters['q']) ?>" placeholder="邮箱 / 姓名 / 备注"></div>
        <div class="field">
            <label>状态</label>
            <select name="status">
                <option value="">全部</option>
                <option value="1" <?= $filters['status'] === '1' ? 'selected' : '' ?>>启用</option>
                <option value="0" <?= $filters['status'] === '0' ? 'selected' : '' ?>>禁用</option>
            </select>
        </div>
        <div class="field">
            <label>密码</label>
            <div class="checkbox"><input type="checkbox" name="password_known" value="1" id="pk" <?= !empty($filters['password_known']) ? 'checked' : '' ?>><label for="pk" style="margin:0">仅显示已保存密码的账号</label></div>
        </div>
        <div class="field" style="flex:0 0 auto"><label>&nbsp;</label><button class="btn" type="submit">筛选</button></div>
    </form>
    <div class="table-wrap">
        <table>
            <thead><tr><th>账号</th><th>服务器</th><th>显示名</th><th>配额</th><th>已用</th><th>状态</th><th>密码</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($result['items'] as $user): ?>
                <tr>
                    <td>
                        <a href="<?= e(url('/users/' . $user['id'])) ?>"><?= e($user['email']) ?></a>
                        <?php if ((int) $user['global_admin'] === 1): ?><span class="badge badge-warn">管理员</span><?php endif; ?>
                        <?php if ((int) $user['remote_exists'] !== 1): ?><span class="badge badge-bad">服务器上已删除</span><?php endif; ?>
                    </td>
                    <td class="small muted"><?= e($user['server_name']) ?></td>
                    <td class="small"><?= e($user['displayed_name']) ?></td>
                    <td><?= e(format_bytes($user['quota_bytes'])) ?></td>
                    <td><?= e(format_bytes($user['quota_bytes_used'])) ?></td>
                    <td><?= (int) $user['enabled'] === 1 ? '<span class="badge badge-ok">启用</span>' : '<span class="badge badge-bad">禁用</span>' ?></td>
                    <td class="small"><?= !empty($user['password_enc']) ? '<span class="badge badge-ok">已保存</span>' : '<span class="badge badge-muted">未知</span>' ?></td>
                    <td>
                        <div class="btn-row">
                            <a class="btn btn-sm" href="<?= e(url('/users/' . $user['id'])) ?>">详情</a>
                            <a class="btn btn-sm" href="<?= e(url('/users/' . $user['id'] . '/credentials')) ?>" target="_blank" rel="noopener">登录信息</a>
                            <form method="post" action="<?= e(url('/users/' . $user['id'] . '/toggle')) ?>"><?= csrf_field() ?><button class="btn btn-sm" type="submit"><?= (int) $user['enabled'] === 1 ? '禁用' : '启用' ?></button></form>
                            <form method="post" action="<?= e(url('/users/' . $user['id'] . '/password')) ?>"><?= csrf_field() ?><button class="btn btn-sm" type="submit" data-confirm="确定重置该账号密码？新密码会显示在页面上。">重置密码</button></form>
                            <form method="post" action="<?= e(url('/users/' . $user['id'] . '/delete')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-danger" type="submit" data-confirm="删除账号会同时删除其邮箱数据，确定继续？">删除</button></form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$result['items']): ?>
                <tr><td colspan="8" class="muted">没有账号。可点击右上角“新建账号”或“批量创建”，也可以先同步服务器数据。</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php partial('partials/pagination'); ?>
</div>