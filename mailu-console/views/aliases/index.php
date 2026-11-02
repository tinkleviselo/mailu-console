<div class="card">
    <div class="flex-between">
        <h2>别名 / 转发 / 邮件组</h2>
        <div class="btn-row">
            <form method="post" action="<?= e(url('/aliases/sync')) ?>"><?= csrf_field() ?><button class="btn" type="submit">从服务器同步</button></form>
            <a class="btn btn-primary" href="<?= e(url('/aliases/create')) ?>">新建别名</a>
        </div>
    </div>
    <form method="get" action="<?= e(url('/aliases')) ?>" class="row">
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
        <div class="field"><label>搜索</label><input type="text" name="q" value="<?= e($filters['q']) ?>" placeholder="别名或目标地址"></div>
        <div class="field" style="flex:0 0 auto"><label>&nbsp;</label><button class="btn" type="submit">筛选</button></div>
    </form>
    <div class="table-wrap">
        <table>
            <thead><tr><th>别名地址</th><th>转发到</th><th>服务器</th><th>备注</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($result['items'] as $alias): ?>
                <tr>
                    <td class="mono small"><?= e($alias['email']) ?></td>
                    <td class="mono small"><?= e(implode(', ', $alias['destinations'])) ?></td>
                    <td class="small muted"><?= e($alias['server_name']) ?></td>
                    <td class="small muted"><?= e($alias['comment']) ?></td>
                    <td>
                        <div class="btn-row">
                            <details>
                                <summary>编辑</summary>
                                <form method="post" action="<?= e(url('/aliases/' . $alias['id'])) ?>" class="mt">
                                    <?= csrf_field() ?>
                                    <div class="field"><label>转发到（逗号分隔）</label><input type="text" name="destination" value="<?= e(implode(',', $alias['destinations'])) ?>"></div>
                                    <div class="field"><label>备注</label><input type="text" name="comment" value="<?= e($alias['comment']) ?>"></div>
                                    <button class="btn btn-sm btn-primary mt" type="submit">保存</button>
                                </form>
                            </details>
                            <form method="post" action="<?= e(url('/aliases/' . $alias['id'] . '/delete')) ?>">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-danger" type="submit" data-confirm="确定删除该别名？">删除</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$result['items']): ?><tr><td colspan="5" class="muted">没有别名</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php partial('partials/pagination'); ?>
</div>