<form method="post" action="<?= e(url('/aliases')) ?>" data-guard>
    <?= csrf_field() ?>
    <div class="card">
        <h2>新建别名 / 转发</h2>
        <p class="muted small">别名可以把一个地址（如 info@example.com）转发到一个或多个真实邮箱，也可以做邮件组。</p>
        <div class="row">
            <div class="field">
                <label>所属服务器 *</label>
                <select name="server_id" required>
                    <option value="">请选择</option>
                    <?php foreach ($servers as $server): ?>
                        <option value="<?= (int) $server['id'] ?>" <?= (int) $alias['server_id'] === (int) $server['id'] ? 'selected' : '' ?>><?= e($server['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>域名 *</label>
                <select name="domain_name" required>
                    <option value="">请选择</option>
                    <?php foreach ($domains as $domain): ?>
                        <option value="<?= e($domain['name']) ?>"><?= e($domain['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>别名前缀 *</label>
                <input type="text" name="local_part" required placeholder="info">
            </div>
        </div>
        <div class="row">
            <div class="field">
                <label>转发到（可多个，逗号分隔）*</label>
                <input type="text" name="destination" required placeholder="zhangsan@example.com,lisi@example.com">
            </div>
            <div class="field"><label>备注</label><input type="text" name="comment" placeholder="例如 客服邮箱"></div>
        </div>
    </div>
    <div class="btn-row">
        <button class="btn btn-primary" type="submit" data-busy="保存中...">保存</button>
        <a class="btn" href="<?= e(url('/aliases')) ?>">返回列表</a>
    </div>
</form>