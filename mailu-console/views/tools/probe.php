<div class="card">
    <h2>收发邮件登录测试</h2>
    <p class="muted small">直接连接 IMAP / POP3 / SMTP 端口验证账号能否登录与发信（只做登录握手，不会发送任何邮件）。</p>
    <form method="post" action="<?= e(url('/tools/probe')) ?>" data-guard>
        <?= csrf_field() ?>
        <div class="row">
            <div class="field">
                <label>邮件服务器 *</label>
                <select name="server_id" required>
                    <option value="">请选择</option>
                    <?php foreach ($servers as $server): ?>
                        <option value="<?= (int) $server['id'] ?>" <?= (isset($tested['server']) && $tested['server'] === $server['name']) ? 'selected' : '' ?>><?= e($server['name']) ?> (<?= e($server['base_url']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>邮箱地址 *</label>
                <input type="text" name="email" value="<?= e(isset($tested['email']) ? $tested['email'] : '') ?>" required placeholder="user@example.com">
            </div>
            <div class="field">
                <label>密码</label>
                <input type="text" name="password" value="" autocomplete="off" placeholder="留空则使用中台保存的密码">
            </div>
        </div>
        <div class="row">
            <div class="field">
                <label>测试项目</label>
                <div class="checkbox"><input type="checkbox" name="test_imap" value="1" id="t_imap" checked><label for="t_imap" style="margin:0">IMAP 收信登录 (993)</label></div>
                <div class="checkbox"><input type="checkbox" name="test_smtp" value="1" id="t_smtp" checked><label for="t_smtp" style="margin:0">SMTP 发信认证 (465/587)</label></div>
                <div class="checkbox"><input type="checkbox" name="test_pop3" value="1" id="t_pop3"><label for="t_pop3" style="margin:0">POP3 收信登录 (995)</label></div>
                <div class="checkbox"><input type="checkbox" name="test_sieve" value="1" id="t_sieve"><label for="t_sieve" style="margin:0">Sieve 端口连通性 (4190)</label></div>
            </div>
        </div>
        <button class="btn btn-primary" type="submit" data-busy="测试中，请稍候...">开始测试</button>
    </form>
</div>

<?php if ($results): ?>
    <div class="card">
        <h3>测试结果：<?= e($tested['email']) ?></h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>协议</th><th>目标</th><th>结果</th><th>耗时</th><th>说明</th></tr></thead>
                <tbody>
                <?php foreach ($results as $name => $item): ?>
                    <tr>
                        <td><?= e($item['protocol']) ?> <span class="muted small">(<?= e($name) ?>)</span></td>
                        <td class="mono small"><?= e($item['target']) ?></td>
                        <td><?= $item['ok'] ? '<span class="badge badge-ok">成功</span>' : '<span class="badge badge-bad">失败</span>' ?></td>
                        <td class="small muted"><?= (int) $item['ms'] ?> ms</td>
                        <td class="small"><?= e($item['message']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="small muted mt">如果全部失败，请检查：VPS 防火墙是否放行 25/465/587/993/995 端口、域名解析是否已指向服务器、Mailu 容器是否正常运行。</p>
    </div>
<?php endif; ?>