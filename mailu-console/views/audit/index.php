<div class="card">
    <h2>操作审计</h2>
    <form method="get" action="<?= e(url('/audit')) ?>" class="row">
        <div class="field">
            <label>服务器</label>
            <select name="server_id">
                <option value="">全部</option>
                <?php foreach ($servers as $server): ?>
                    <option value="<?= (int) $server['id'] ?>" <?= (int) $filters['server_id'] === (int) $server['id'] ? 'selected' : '' ?>><?= e($server['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field"><label>动作</label><input type="text" name="action" value="<?= e($filters['action']) ?>" placeholder="user.create"></div>
        <div class="field"><label>对象</label><input type="text" name="target" value="<?= e($filters['target']) ?>" placeholder="user@example.com"></div>
        <div class="field">
            <label>结果</label>
            <select name="result">
                <option value="">全部</option>
                <option value="ok" <?= $filters['result'] === 'ok' ? 'selected' : '' ?>>成功</option>
                <option value="failed" <?= $filters['result'] === 'failed' ? 'selected' : '' ?>>失败</option>
                <option value="partial" <?= $filters['result'] === 'partial' ? 'selected' : '' ?>>部分成功</option>
            </select>
        </div>
        <div class="field" style="flex:0 0 auto"><label>&nbsp;</label><button class="btn" type="submit">筛选</button></div>
    </form>
    <div class="table-wrap">
        <table>
            <thead><tr><th>时间</th><th>操作者</th><th>动作</th><th>类型</th><th>对象</th><th>结果</th><th>来源 IP</th><th>详情</th></tr></thead>
            <tbody>
            <?php foreach ($result['items'] as $log): ?>
                <tr>
                    <td class="small muted"><?= e($log['created_at']) ?></td>
                    <td><?= e($log['admin_name']) ?></td>
                    <td class="mono small"><?= e($log['action']) ?></td>
                    <td class="small"><?= e($log['target_type']) ?></td>
                    <td class="small"><?= e($log['target_label']) ?></td>
                    <td><?= status_badge($log['result']) ?></td>
                    <td class="small mono"><?= e($log['ip']) ?></td>
                    <td class="small muted">
                        <?= e(str_limit($log['message'], 60)) ?>
                        <?php if (!empty($log['meta_json']) && $log['meta_json'] !== '[]'): ?>
                            <details><summary>数据</summary><pre class="log"><?= e(json_encode(json_field($log['meta_json']), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre></details>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$result['items']): ?><tr><td colspan="8" class="muted">暂无记录</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php partial('partials/pagination'); ?>
</div>