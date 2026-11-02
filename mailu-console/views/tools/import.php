<div class="card">
    <h2>CSV 批量导入</h2>
    <p class="muted small">支持账号与别名两类导入。CSV 第一行是表头，可用列名：email, password, quota_mb, name, comment（别名：email, destination, comment）。</p>
    <form method="post" action="<?= e(url('/tools/import')) ?>" enctype="multipart/form-data" data-guard>
        <?= csrf_field() ?>
        <div class="row">
            <div class="field">
                <label>导入类型</label>
                <select name="mode">
                    <option value="users" <?= $mode === 'users' ? 'selected' : '' ?>>邮箱账号</option>
                    <option value="aliases" <?= $mode === 'aliases' ? 'selected' : '' ?>>别名 / 转发</option>
                </select>
            </div>
            <div class="field">
                <label>目标服务器 *</label>
                <select name="server_id" required>
                    <option value="">请选择</option>
                    <?php foreach ($servers as $server): ?>
                        <option value="<?= (int) $server['id'] ?>"><?= e($server['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>模式</label>
                <div class="checkbox"><input type="checkbox" name="dry_run" value="1" id="dry" checked><label for="dry" style="margin:0">仅校验不写入（推荐先试运行）</label></div>
            </div>
        </div>
        <div class="row">
            <div class="field">
                <label>上传 CSV 文件</label>
                <input type="file" name="csv_file" accept=".csv,.txt">
            </div>
            <div class="field" style="flex:2 1 420px">
                <label>或直接粘贴 CSV 内容</label>
                <textarea name="csv_text" rows="8" placeholder="email,password,quota_mb,name&#10;zhangsan@example.com,Passw0rd!23,2048,张三"></textarea>
            </div>
        </div>
        <button class="btn btn-primary" type="submit" data-busy="导入中...">开始导入</button>
    </form>
</div>

<?php if ($result): ?>
    <div class="card">
        <h3>导入结果</h3>
        <p>共 <?= (int) $result['total'] ?> 行，成功 <?= (int) $result['created'] ?>，失败 <?= (int) $result['failed'] ?>。</p>
        <div class="table-wrap">
            <table>
                <thead><tr><th>行号</th><th>对象</th><th>结果</th><th>说明</th><th>密码</th></tr></thead>
                <tbody>
                <?php foreach ($result['results'] as $row): ?>
                    <tr>
                        <td><?= (int) $row['row'] ?></td>
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