<?php $allOk = true; foreach ($checks as $check) { if (!$check['ok']) { $allOk = false; } } ?>
<div class="card">
    <h1>初始化 Mailu 邮件中台</h1>
    <p class="muted small">完成初始化后会生成 config/config.php 与本地数据库，并创建管理员账号。</p>
    <h3>环境检查</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>项目</th><th>状态</th><th>值</th></tr></thead>
            <tbody>
            <?php foreach ($checks as $check): ?>
                <tr>
                    <td><?= e($check['label']) ?></td>
                    <td><?= badge($check['ok'], '通过', '不满足') ?></td>
                    <td class="mono small"><?= e($check['value']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h3>创建管理员</h3>
    <form method="post" action="<?= e(url('/setup')) ?>" data-guard>
        <?= csrf_field() ?>
        <div class="row">
            <div class="field">
                <label>中台名称</label>
                <input type="text" name="app_name" value="<?= e(old('app_name', $defaults['app_name'])) ?>" required>
            </div>
            <div class="field">
                <label>时区</label>
                <input type="text" name="timezone" value="<?= e(old('timezone', $defaults['timezone'])) ?>">
            </div>
            <div class="field">
                <label>子目录 (可选)</label>
                <input type="text" name="base_path" value="<?= e(old('base_path', $defaults['base_path'])) ?>" placeholder="/console">
                <div class="hint">如果中台部署在 https://example.com/console，请填 console</div>
            </div>
        </div>
        <div class="row">
            <div class="field">
                <label>管理员账号</label>
                <input type="text" name="admin_username" value="<?= e(old('admin_username', 'admin')) ?>" required>
            </div>
            <div class="field">
                <label>管理员邮箱 (可选)</label>
                <input type="text" name="admin_email" value="<?= e(old('admin_email', '')) ?>">
            </div>
        </div>
        <div class="row">
            <div class="field">
                <label>管理员密码 (至少 10 位)</label>
                <input type="password" name="admin_password" id="admin_password" required>
                <div class="hint"><span class="copyable" data-generate-password="#admin_password" data-length="18">点击生成强密码</span></div>
            </div>
            <div class="field">
                <label>确认密码</label>
                <input type="password" name="admin_password_confirm" required>
            </div>
        </div>
        <h3 class="mt">数据库</h3>
        <div class="row">
            <div class="field">
                <label>数据库类型</label>
                <select name="db_driver">
                    <option value="sqlite" <?= old('db_driver', $defaults['db_driver']) === 'sqlite' ? 'selected' : '' ?>>SQLite（推荐，免配置）</option>
                    <option value="mysql" <?= old('db_driver') === 'mysql' ? 'selected' : '' ?>>MySQL / MariaDB</option>
                </select>
            </div>
            <div class="field">
                <label>SQLite 文件名</label>
                <input type="text" name="db_database" value="<?= e(old('db_database', 'mailu.sqlite')) ?>">
                <div class="hint">文件位于 storage/ 目录</div>
            </div>
            <div class="field">
                <label>MySQL 主机</label>
                <input type="text" name="db_host" value="<?= e(old('db_host', '127.0.0.1')) ?>">
            </div>
            <div class="field">
                <label>MySQL 端口</label>
                <input type="number" name="db_port" value="<?= e(old('db_port', '3306')) ?>">
            </div>
            <div class="field">
                <label>MySQL 数据库名</label>
                <input type="text" name="db_name" value="<?= e(old('db_name', 'mailu_console')) ?>">
            </div>
            <div class="field">
                <label>MySQL 用户名</label>
                <input type="text" name="db_username" value="<?= e(old('db_username', '')) ?>">
            </div>
            <div class="field">
                <label>MySQL 密码</label>
                <input type="password" name="db_password" value="">
            </div>
        </div>
        <h3 class="mt">默认设置</h3>
        <div class="row">
            <div class="field">
                <label>默认安装的 Mailu 版本</label>
                <input type="text" name="mailu_version" value="<?= e(old('mailu_version', '2024.06.61')) ?>">
                <div class="hint">生成部署脚本时使用的 Mailu 发行版本 (GitHub tag)</div>
            </div>
            <div class="field">
                <label>下载加速前缀 (可选)</label>
                <input type="text" name="mirror_prefix" value="<?= e(old('mirror_prefix', '')) ?>" placeholder="https://ghproxy.net/">
                <div class="hint">国内 VPS 拉取 GitHub 安装包慢时可填写</div>
            </div>
        </div>
        <button class="btn btn-primary" type="submit" data-busy="初始化中...">开始初始化</button>
        <?php if (!$allOk): ?>
            <span class="badge badge-warn">存在不满足的检查项，仍可尝试安装</span>
        <?php endif; ?>
    </form>
</div>