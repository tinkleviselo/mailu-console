<form method="post" action="<?= e($isEdit ? url('/users/' . $user['id']) : url('/users')) ?>" data-guard>
    <?= csrf_field() ?>
    <div class="card">
        <h2><?= $isEdit ? ('编辑账号：' . e($user['email'])) : '新建邮箱账号' ?></h2>
        <?php if (!$isEdit): ?>
            <div class="row">
                <div class="field">
                    <label>所属服务器 *</label>
                    <select name="server_id" required>
                        <option value="">请选择</option>
                        <?php foreach ($servers as $server): ?>
                            <option value="<?= (int) $server['id'] ?>" <?= (int) $user['server_id'] === (int) $server['id'] ? 'selected' : '' ?>><?= e($server['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>域名 *</label>
                    <select name="domain_name" required>
                        <option value="">请选择</option>
                        <?php foreach ($domains as $domain): ?>
                            <option value="<?= e($domain['name']) ?>" <?= $user['domain_name'] === $domain['name'] ? 'selected' : '' ?>><?= e($domain['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="hint">没有想要的域名？先到“域名管理”添加</div>
                </div>
                <div class="field">
                    <label>邮箱前缀 *</label>
                    <input type="text" name="local_part" value="<?= e($user['local_part']) ?>" required placeholder="zhangsan">
                    <div class="hint">完整地址 = 前缀 @ 域名</div>
                </div>
            </div>
            <div class="row">
                <div class="field">
                    <label>初始密码</label>
                    <input type="text" name="password" id="user_password" value="" autocomplete="off" placeholder="留空自动生成强密码">
                    <div class="hint"><span class="copyable" data-generate-password="#user_password" data-length="16">点击生成强密码</span></div>
                </div>
            </div>
        <?php endif; ?>
        <div class="row">
            <div class="field"><label>显示名称</label><input type="text" name="displayed_name" value="<?= e($user['displayed_name']) ?>" placeholder="张三"></div>
            <div class="field"><label>备注</label><input type="text" name="comment" value="<?= e($user['comment']) ?>"></div>
            <div class="field">
                <label>邮箱配额 (MB，0 表示不限)</label>
                <input type="number" name="quota_mb" value="<?= (int) round(((int) $user['quota_bytes']) / 1048576) > 0 ? (int) round(((int) $user['quota_bytes']) / 1048576) : (int) $defaultQuotaMb ?>" min="0">
            </div>
            <div class="field"><label>垃圾邮件阈值</label><input type="number" name="spam_threshold" value="<?= (int) $user['spam_threshold'] ?>" min="1" max="999"></div>
        </div>
        <div class="row">
            <div class="field">
                <label>开关</label>
                <div class="checkbox"><input type="checkbox" name="enabled" value="1" id="u_enabled" <?= !empty($user['enabled']) ? 'checked' : '' ?>><label for="u_enabled" style="margin:0">启用账号（可登录收发邮件）</label></div>
                <div class="checkbox"><input type="checkbox" name="enable_imap" value="1" id="u_imap" <?= !empty($user['enable_imap']) ? 'checked' : '' ?>><label for="u_imap" style="margin:0">允许 IMAP 收信</label></div>
                <div class="checkbox"><input type="checkbox" name="enable_pop" value="1" id="u_pop" <?= !empty($user['enable_pop']) ? 'checked' : '' ?>><label for="u_pop" style="margin:0">允许 POP3 收信</label></div>
                <div class="checkbox"><input type="checkbox" name="spam_enabled" value="1" id="u_spam" <?= !empty($user['spam_enabled']) ? 'checked' : '' ?>><label for="u_spam" style="margin:0">启用垃圾邮件过滤</label></div>
                <div class="checkbox"><input type="checkbox" name="global_admin" value="1" id="u_admin" <?= !empty($user['global_admin']) ? 'checked' : '' ?>><label for="u_admin" style="margin:0">设为 Mailu 全局管理员</label></div>
            </div>
            <div class="field">
                <label>邮件转发</label>
                <div class="checkbox"><input type="checkbox" name="forward_enabled" value="1" id="u_fwd" <?= !empty($user['forward_enabled']) ? 'checked' : '' ?>><label for="u_fwd" style="margin:0">启用自动转发</label></div>
                <input type="text" name="forward_destination" value="<?= e(is_array($user['forward_destination']) ? implode(',', $user['forward_destination']) : $user['forward_destination']) ?>" placeholder="多个地址用逗号分隔">
                <div class="hint">启用转发后，收到的邮件会转发到上述地址</div>
            </div>
        </div>
    </div>
    <div class="btn-row">
        <button class="btn btn-primary" type="submit" data-busy="保存中...">保存</button>
        <a class="btn" href="<?= e(url('/users')) ?>">返回列表</a>
    </div>
</form>