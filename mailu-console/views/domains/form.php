<form method="post" action="<?= e($isEdit ? url('/domains/' . $domain['id']) : url('/domains')) ?>" data-guard>
    <?= csrf_field() ?>
    <div class="card">
        <h2><?= $isEdit ? ('编辑域名：' . e($domain['name'])) : '添加域名' ?></h2>
        <?php if (!$isEdit): ?>
            <div class="row">
                <div class="field">
                    <label>所属邮件服务器 *</label>
                    <select name="server_id" required>
                        <option value="">请选择</option>
                        <?php foreach ($servers as $server): ?>
                            <option value="<?= (int) $server['id'] ?>" <?= (int) $domain['server_id'] === (int) $server['id'] ? 'selected' : '' ?>><?= e($server['name']) ?> (<?= e($server['base_url']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>域名 *</label>
                    <input type="text" name="name" value="<?= e($domain['name']) ?>" required placeholder="example.com">
                    <div class="hint">域名需要先解析到该邮件服务器，收信才正常</div>
                </div>
                <div class="field">
                    <label>别名域名 (可选，逗号分隔)</label>
                    <input type="text" name="alternatives" value="<?= e(is_array($domain['alternatives']) ? implode(',', $domain['alternatives']) : $domain['alternatives']) ?>" placeholder="example.net,example.org">
                </div>
            </div>
        <?php else: ?>
            <p class="muted small">域名本身不可修改；如需更换请在 Mailu 上删除后重新添加。</p>
        <?php endif; ?>
        <div class="row">
            <div class="field"><label>备注</label><input type="text" name="comment" value="<?= e($domain['comment']) ?>"></div>
            <div class="field"><label>最大账号数 (-1 不限)</label><input type="number" name="max_users" value="<?= (int) $domain['max_users'] ?>"></div>
            <div class="field"><label>最大别名数 (-1 不限)</label><input type="number" name="max_aliases" value="<?= (int) $domain['max_aliases'] ?>"></div>
            <div class="field"><label>域名配额上限 (MB，0 不限)</label><input type="number" name="max_quota_mb" value="<?= (int) round(((int) $domain['max_quota_bytes']) / 1048576) ?>"></div>
        </div>
        <div class="checkbox">
            <input type="checkbox" name="signup_enabled" value="1" id="signup_enabled" <?= !empty($domain['signup_enabled']) ? 'checked' : '' ?>>
            <label for="signup_enabled" style="margin:0">允许用户自助注册</label>
        </div>
    </div>
    <div class="btn-row">
        <button class="btn btn-primary" type="submit" data-busy="保存中...">保存</button>
        <a class="btn" href="<?= e(url('/domains')) ?>">返回列表</a>
    </div>
</form>