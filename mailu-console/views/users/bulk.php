<div class="card">
    <h2>批量创建邮箱账号</h2>
    <p class="muted small">每行一个账号，格式：<span class="mono">邮箱前缀或完整邮箱,密码,配额MB,显示名称,备注</span>（分隔符支持逗号、分号、竖线、Tab；只填邮箱时密码自动生成）。</p>
    <form method="post" action="<?= e(url('/users/bulk')) ?>" data-guard>
        <?= csrf_field() ?>
        <div class="row">
            <div class="field">
                <label>所属服务器 *</label>
                <select name="server_id" required>
                    <option value="">请选择</option>
                    <?php foreach ($servers as $server): ?>
                        <option value="<?= (int) $server['id'] ?>" <?= (int) $serverId === (int) $server['id'] ? 'selected' : '' ?>><?= e($server['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>默认域名（行内只写前缀时使用）</label>
                <select name="domain_name">
                    <option value="">—</option>
                    <?php foreach ($domains as $domain): ?>
                        <option value="<?= e($domain['name']) ?>"><?= e($domain['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>默认配额 (MB)</label>
                <input type="number" name="quota_mb" value="<?= (int) $defaultQuotaMb ?>" min="0">
            </div>
        </div>
        <div class="field mb">
            <label>账号列表</label>
            <textarea name="accounts" rows="12" placeholder="zhangsan&#10;lisi,Passw0rd!23,2048,李四,销售部&#10;wangwu@example.com,Passw0rd!23,1024,王五"></textarea>
        </div>
        <button class="btn btn-primary" type="submit" data-busy="创建中...">开始创建</button>
    </form>
</div>

<?php if ($result): ?>
    <div class="card">
        <h2>创建结果</h2>
        <p>成功 <strong><?= (int) $result['created'] ?></strong> 个，失败 <strong><?= (int) $result['failed'] ?></strong> 个。</p>
        <div class="table-wrap">
            <table>
                <thead><tr><th>行号</th><th>邮箱</th><th>结果</th><th>说明</th><th>初始密码</th></tr></thead>
                <tbody>
                <?php foreach ($result['results'] as $row): ?>
                    <tr>
                        <td><?= (int) $row['line'] ?></td>
                        <td class="mono small"><?= e($row['email']) ?></td>
                        <td><?= $row['ok'] ? '<span class="badge badge-ok">成功</span>' : '<span class="badge badge-bad">失败</span>' ?></td>
                        <td class="small"><?= e($row['message']) ?></td>
                        <td class="mono small"><?= e(isset($row['password']) ? $row['password'] : '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>