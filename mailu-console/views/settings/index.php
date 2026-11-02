<div class="card">
    <h2>系统设置</h2>
    <form method="post" action="<?= e(url('/settings')) ?>" data-guard>
        <?= csrf_field() ?>
        <div class="row">
            <div class="field">
                <label>新建账号默认配额 (MB)</label>
                <input type="number" name="default_quota_mb" value="<?= (int) \App\Core\Settings::get('default.quota_mb', 2048) ?>" min="0">
            </div>
            <div class="field">
                <label>自动生成密码长度</label>
                <input type="number" name="default_password_length" value="<?= (int) \App\Core\Settings::get('default.password_length', 16) ?>" min="8" max="64">
            </div>
            <div class="field">
                <label>中台对外地址</label>
                <input type="text" name="console_url" value="<?= e(\App\Core\Settings::get('app.console_url', '')) ?>" placeholder="https://console.example.com">
                <div class="hint">用于生成脚本时回填中台地址</div>
            </div>
        </div>
        <div class="row">
            <div class="field">
                <label>开关</label>
                <div class="checkbox"><input type="checkbox" name="probe_enabled" value="1" id="s1" <?= \App\Core\Settings::bool('probe.enabled', true) ? 'checked' : '' ?>><label for="s1" style="margin:0">允许 IMAP/SMTP 登录测试</label></div>
                <div class="checkbox"><input type="checkbox" name="dns_auto_check" value="1" id="s2" <?= \App\Core\Settings::bool('dns.auto_check', true) ? 'checked' : '' ?>><label for="s2" style="margin:0">打开 DNS 页面时自动校验</label></div>
                <div class="checkbox"><input type="checkbox" name="require_strong_password" value="1" id="s3" <?= \App\Core\Settings::bool('security.require_strong_password', true) ? 'checked' : '' ?>><label for="s3" style="margin:0">要求强密码</label></div>
                <div class="checkbox"><input type="checkbox" name="sync_auto" value="1" id="s4" <?= \App\Core\Settings::bool('sync.auto_on_dashboard', false) ? 'checked' : '' ?>><label for="s4" style="margin:0">打开总览时自动同步</label></div>
            </div>
        </div>
        <button class="btn btn-primary" type="submit" data-busy="保存中...">保存设置</button>
    </form>
</div>

<div class="grid cols-2">
    <div class="card">
        <h3>修改管理员密码</h3>
        <form method="post" action="<?= e(url('/settings/password')) ?>" data-guard>
            <?= csrf_field() ?>
            <div class="field mb"><label>当前密码</label><input type="password" name="current_password" required autocomplete="current-password"></div>
            <div class="field mb"><label>新密码（至少 10 位）</label><input type="password" name="new_password" id="new_pw" required></div>
            <div class="field mb"><label>确认新密码</label><input type="password" name="new_password_confirm" required></div>
            <button class="btn btn-primary" type="submit">更新密码</button>
        </form>
    </div>

    <div class="card">
        <h3>中台 API 令牌</h3>
        <p class="muted small">用于外部系统（财务/开通系统）通过 REST 接口创建账号、重置密码等。请求头：<span class="mono">Authorization: Bearer &lt;token&gt;</span></p>
        <?php if ($apiToken): ?>
            <div class="field mb">
                <label>当前令牌</label>
                <input type="text" value="<?= e($apiToken) ?>" readonly onclick="this.select()">
            </div>
        <?php else: ?>
            <p class="badge badge-warn">尚未生成令牌，请点击下方按钮生成</p>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/settings/api-token')) ?>">
            <?= csrf_field() ?>
            <button class="btn" type="submit" data-confirm="重新生成令牌后，旧的令牌会立即失效，确定继续？"><?= $apiToken ? '重新生成令牌' : '生成令牌' ?></button>
        </form>
        <h3 class="mt">接口示例</h3>
        <pre class="log">curl -H "Authorization: Bearer <?= e($apiToken ? $apiToken : 'TOKEN') ?>" \
  "<?= e(url('/api/v1/users?server_id=1&per_page=20')) ?>"

curl -X POST -H "Authorization: Bearer <?= e($apiToken ? $apiToken : 'TOKEN') ?>" \
  -d "server_id=1" -d "email=user@example.com" -d "quota_mb=2048" \
  "<?= e(url('/api/v1/users/create')) ?>"</pre>
    </div>
</div>

<div class="grid cols-2">
    <div class="card">
        <h3>运行环境</h3>
        <dl class="kv">
            <dt>PHP 版本</dt><dd><?= e($phpVersion) ?></dd>
            <dt>数据库</dt><dd><?= e(isset($config['db']['driver']) ? $config['db']['driver'] : 'sqlite') ?> · <?= e(isset($config['db']['database']) ? $config['db']['database'] : '') ?></dd>
            <dt>存储目录</dt><dd class="mono small"><?= e(STORAGE_PATH) ?></dd>
            <dt>配置文件</dt><dd class="mono small"><?= e(CONFIG_PATH) ?></dd>
            <dt>cURL</dt><dd><?= function_exists('curl_init') ? '可用' : '不可用（将使用 stream 回退）' ?></dd>
            <dt>DNS 查询</dt><dd><?= function_exists('dns_get_record') ? '可用' : '不可用' ?></dd>
            <dt>ZipArchive</dt><dd><?= class_exists('ZipArchive') ? '可用' : '不可用（部署包将回退为单文件脚本）' ?></dd>
            <dt>远程部署</dt><dd><?= $remote['ready'] ? '已启用' : '未启用' ?><?= $remote['allowed'] ? '' : '（config.php: deploy.allow_remote_deploy）' ?></dd>
            <dt>统计</dt><dd><?= (int) $stats['servers'] ?> 台服务器 · <?= (int) $stats['domains'] ?> 个域名 · <?= (int) $stats['users'] ?> 个账号 · <?= (int) $stats['aliases'] ?> 个别名</dd>
        </dl>
    </div>
    <div class="card">
        <h3>最近日志（storage/logs）</h3>
        <pre class="log"><?= e(implode("\n", $logs)) ?></pre>
    </div>
</div>