<form method="post" action="<?= e($isEdit ? url('/servers/' . $server['id']) : url('/servers')) ?>" data-guard>
    <?= csrf_field() ?>
    <div class="card">
        <h2><?= $isEdit ? '编辑邮件服务器' : '添加邮件服务器' ?></h2>
        <div class="row">
            <div class="field">
                <label>名称 *</label>
                <input type="text" name="name" value="<?= e($server['name']) ?>" required placeholder="主邮件服务器 / 香港节点">
            </div>
            <div class="field">
                <label>访问地址 (基地址) *</label>
                <input type="url" name="base_url" value="<?= e($server['base_url']) ?>" required placeholder="https://mail.example.com">
                <div class="hint">Mailu 对外访问地址，不含 /admin 或 /api</div>
            </div>
            <div class="field">
                <label>API 路径</label>
                <input type="text" name="api_path" value="<?= e($server['api_path']) ?>" placeholder="/api/v1">
            </div>
        </div>
        <div class="row">
            <div class="field">
                <label>认证方式</label>
                <select name="auth_mode">
                    <option value="bearer" <?= $server['auth_mode'] === 'bearer' ? 'selected' : '' ?>>API 令牌 (Bearer Token，推荐)</option>
                    <option value="basic" <?= $server['auth_mode'] === 'basic' ? 'selected' : '' ?>>管理员账号 (HTTP Basic)</option>
                </select>
                <div class="hint">API 令牌来自 mailu.env 中的 API_TOKEN</div>
            </div>
            <div class="field">
                <label>API 令牌 <?= $isEdit ? '(留空表示不修改)' : '' ?></label>
                <input type="text" name="api_token" value="" autocomplete="off">
            </div>
            <div class="field">
                <label>管理员账号 (Basic 认证)</label>
                <input type="text" name="api_user" value="" autocomplete="off" placeholder="admin@example.com">
            </div>
            <div class="field">
                <label>管理员密码 (Basic 认证)</label>
                <input type="password" name="api_pass" value="" autocomplete="off">
            </div>
        </div>
        <div class="row">
            <div class="field"><label>请求超时 (秒)</label><input type="number" name="timeout" value="<?= (int) $server['timeout'] ?>" min="5" max="120"></div>
            <div class="field"><label>DKIM 选择器</label><input type="text" name="dkim_selector" value="<?= e($server['dkim_selector']) ?>"></div>
            <div class="field">
                <label>选项</label>
                <div class="checkbox"><input type="checkbox" name="verify_tls" value="1" id="verify_tls" <?= !empty($server['verify_tls']) ? 'checked' : '' ?>><label for="verify_tls" style="margin:0">校验 TLS 证书（自签证书时关闭）</label></div>
                <div class="checkbox"><input type="checkbox" name="enabled" value="1" id="enabled" <?= !empty($server['enabled']) ? 'checked' : '' ?>><label for="enabled" style="margin:0">启用（参与同步）</label></div>
            </div>
        </div>
    </div>

    <div class="card">
        <h3>收发邮件登录信息（用于生成用户登录卡片）</h3>
        <p class="muted small">留空时自动使用访问地址的主机名，端口使用 Mailu 默认值（IMAP 993 / POP3 995 / SMTP 465）。</p>
        <div class="row">
            <div class="field"><label>IMAP 主机</label><input type="text" name="imap_host" value="<?= e($server['imap_host']) ?>"></div>
            <div class="field"><label>IMAP 端口</label><input type="number" name="imap_port" value="<?= (int) $server['imap_port'] ?>"></div>
            <div class="field"><label>IMAP 加密</label><select name="imap_security"><?php foreach (array('ssl', 'starttls', 'none') as $o): ?><option value="<?= e($o) ?>" <?= $server['imap_security'] === $o ? 'selected' : '' ?>><?= e(strtoupper($o)) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="row">
            <div class="field"><label>POP3 主机</label><input type="text" name="pop3_host" value="<?= e($server['pop3_host']) ?>"></div>
            <div class="field"><label>POP3 端口</label><input type="number" name="pop3_port" value="<?= (int) $server['pop3_port'] ?>"></div>
            <div class="field"><label>POP3 加密</label><select name="pop3_security"><?php foreach (array('ssl', 'starttls', 'none') as $o): ?><option value="<?= e($o) ?>" <?= $server['pop3_security'] === $o ? 'selected' : '' ?>><?= e(strtoupper($o)) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="row">
            <div class="field"><label>SMTP 主机</label><input type="text" name="smtp_host" value="<?= e($server['smtp_host']) ?>"></div>
            <div class="field"><label>SMTP 端口</label><input type="number" name="smtp_port" value="<?= (int) $server['smtp_port'] ?>"></div>
            <div class="field"><label>SMTP 加密</label><select name="smtp_security"><?php foreach (array('ssl', 'starttls', 'none') as $o): ?><option value="<?= e($o) ?>" <?= $server['smtp_security'] === $o ? 'selected' : '' ?>><?= e(strtoupper($o)) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="row">
            <div class="field"><label>Sieve 主机 (可选)</label><input type="text" name="sieve_host" value="<?= e($server['sieve_host']) ?>"></div>
            <div class="field"><label>Sieve 端口</label><input type="number" name="sieve_port" value="<?= (int) $server['sieve_port'] ?>"></div>
            <div class="field"><label>网页邮箱地址</label><input type="text" name="webmail_url" value="<?= e($server['webmail_url']) ?>" placeholder="https://mail.example.com/webmail"></div>
        </div>
        <div class="row">
            <div class="field"><label>管理后台地址</label><input type="text" name="admin_url" value="<?= e($server['admin_url']) ?>" placeholder="https://mail.example.com/admin"></div>
            <div class="field" style="flex:2 1 420px"><label>备注</label><input type="text" name="note" value="<?= e($server['note']) ?>"></div>
        </div>
    </div>

    <div class="btn-row">
        <button class="btn btn-primary" type="submit" data-busy="保存中...">保存</button>
        <a class="btn" href="<?= e(url('/servers')) ?>">取消</a>
    </div>
</form>
<?php if ($isEdit): ?>
    <form method="post" action="<?= e(url('/servers/' . $server['id'] . '/delete')) ?>" class="mt">
        <?= csrf_field() ?>
        <button class="btn btn-danger" type="submit" data-confirm="确定要从列表中删除该服务器吗？邮件系统本身不会被卸载。">从列表删除该服务器</button>
    </form>
<?php endif; ?>