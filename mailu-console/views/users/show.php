<div class="card">
    <div class="flex-between">
        <h2><?= e($user['email']) ?></h2>
        <div class="btn-row">
            <a class="btn" href="<?= e(url('/users/' . $user['id'] . '/credentials')) ?>" target="_blank" rel="noopener">打印登录信息</a>
            <form method="post" action="<?= e(url('/users/' . $user['id'] . '/sync')) ?>"><?= csrf_field() ?><button class="btn" type="submit">刷新</button></form>
            <form method="post" action="<?= e(url('/users/' . $user['id'] . '/toggle')) ?>"><?= csrf_field() ?><button class="btn" type="submit"><?= (int) $user['enabled'] === 1 ? '禁用账号' : '启用账号' ?></button></form>
            <form method="post" action="<?= e(url('/users/' . $user['id'] . '/delete')) ?>"><?= csrf_field() ?><button class="btn btn-danger" type="submit" data-confirm="删除账号会同时删除邮箱数据，确定继续？">删除账号</button></form>
        </div>
    </div>
    <dl class="kv">
        <dt>所属服务器</dt><dd><a href="<?= e(url('/servers/' . $server['id'])) ?>"><?= e($server['name']) ?></a></dd>
        <dt>状态</dt><dd><?= (int) $user['enabled'] === 1 ? '<span class="badge badge-ok">启用</span>' : '<span class="badge badge-bad">已禁用</span>' ?> <?= (int) $user['global_admin'] === 1 ? '<span class="badge badge-warn">全局管理员</span>' : '' ?></dd>
        <dt>配额</dt><dd><?= e(format_bytes($user['quota_bytes'])) ?>（已用 <?= e(format_bytes($user['quota_bytes_used'])) ?>）</dd>
        <dt>密码</dt><dd><?= $card['password_known'] ? '<span class="mono copyable" data-copy="' . e($card['password']) . '">' . e($card['password']) . '</span> <span class="small muted">点击复制</span>' : '<span class="badge badge-muted">未知（该账号不是通过中台创建的）</span>' ?></dd>
        <dt>显示名 / 备注</dt><dd><?= e($user['displayed_name']) ?> <?= e($user['comment']) ?></dd>
        <dt>转发</dt><dd><?= (int) $user['forward_enabled'] === 1 ? e(implode(', ', json_field($user['forward_destination']))) : '未启用' ?></dd>
        <dt>最近同步</dt><dd><?= e(human_time($user['synced_at'])) ?></dd>
    </dl>
</div>

<div class="card">
    <h3>收发邮件登录信息</h3>
    <?php partial('users/credential_card', array('card' => $card, 'user' => $user)); ?>
    <div class="btn-row mt">
        <a class="btn" href="<?= e(url('/users/' . $user['id'] . '?probe=1')) ?>">测试 IMAP/SMTP 登录</a>
        <a class="btn" href="<?= e(url('/users/' . $user['id'] . '/credentials?format=txt')) ?>">下载 TXT</a>
        <a class="btn" href="<?= e(url('/users/' . $user['id'] . '/credentials?format=csv')) ?>">下载 CSV</a>
        <a class="btn" href="<?= e(url('/users/' . $user['id'] . '/credentials?format=xml')) ?>">下载 Outlook/Thunderbird 自动配置</a>
    </div>
    <?php if ($probe): ?>
        <div class="mt">
            <h3>连通性测试结果</h3>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>协议</th><th>目标</th><th>结果</th><th>耗时</th><th>说明</th></tr></thead>
                    <tbody>
                    <?php foreach ($probe as $name => $item): ?>
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
        </div>
    <?php endif; ?>
</div>

<div class="grid cols-2">
    <div class="card">
        <h3>修改账号设置</h3>
        <form method="post" action="<?= e(url('/users/' . $user['id'])) ?>" data-guard>
            <?= csrf_field() ?>
            <div class="row">
                <div class="field"><label>显示名称</label><input type="text" name="displayed_name" value="<?= e($user['displayed_name']) ?>"></div>
                <div class="field"><label>备注</label><input type="text" name="comment" value="<?= e($user['comment']) ?>"></div>
            </div>
            <div class="row">
                <div class="field"><label>配额 (MB)</label><input type="number" name="quota_mb" value="<?= (int) round(((int) $user['quota_bytes']) / 1048576) ?>" min="0"></div>
                <div class="field"><label>垃圾邮件阈值</label><input type="number" name="spam_threshold" value="<?= (int) $user['spam_threshold'] ?>"></div>
            </div>
            <div class="row">
                <div class="field">
                    <label>开关</label>
                    <div class="checkbox"><input type="checkbox" name="enabled" value="1" id="e_enabled" <?= !empty($user['enabled']) ? 'checked' : '' ?>><label for="e_enabled" style="margin:0">启用账号</label></div>
                    <div class="checkbox"><input type="checkbox" name="enable_imap" value="1" id="e_imap" <?= !empty($user['enable_imap']) ? 'checked' : '' ?>><label for="e_imap" style="margin:0">允许 IMAP</label></div>
                    <div class="checkbox"><input type="checkbox" name="enable_pop" value="1" id="e_pop" <?= !empty($user['enable_pop']) ? 'checked' : '' ?>><label for="e_pop" style="margin:0">允许 POP3</label></div>
                    <div class="checkbox"><input type="checkbox" name="spam_enabled" value="1" id="e_spam" <?= !empty($user['spam_enabled']) ? 'checked' : '' ?>><label for="e_spam" style="margin:0">垃圾邮件过滤</label></div>
                    <div class="checkbox"><input type="checkbox" name="global_admin" value="1" id="e_admin" <?= !empty($user['global_admin']) ? 'checked' : '' ?>><label for="e_admin" style="margin:0">全局管理员</label></div>
                </div>
                <div class="field">
                    <label>转发设置</label>
                    <div class="checkbox"><input type="checkbox" name="forward_enabled" value="1" id="e_fwd" <?= !empty($user['forward_enabled']) ? 'checked' : '' ?>><label for="e_fwd" style="margin:0">启用自动转发</label></div>
                    <input type="text" name="forward_destination" value="<?= e(implode(', ', json_field($user['forward_destination']))) ?>" placeholder="a@example.com,b@example.com">
                    <label class="mt">同时修改密码（留空不改）</label>
                    <input type="text" name="password" value="" autocomplete="off" placeholder="新密码">
                </div>
            </div>
            <button class="btn btn-primary" type="submit" data-busy="保存中...">保存设置</button>
        </form>
    </div>

    <div class="card">
        <h3>重置密码</h3>
        <form method="post" action="<?= e(url('/users/' . $user['id'] . '/password')) ?>" data-guard>
            <?= csrf_field() ?>
            <div class="field mb">
                <label>新密码（留空则随机生成）</label>
                <input type="text" name="new_password" id="reset_password" value="" autocomplete="off">
                <div class="hint"><span class="copyable" data-generate-password="#reset_password" data-length="16">点击生成强密码</span></div>
            </div>
            <button class="btn btn-primary" type="submit">重置密码并显示</button>
        </form>
        <h3 class="mt">操作记录</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>时间</th><th>动作</th><th>结果</th></tr></thead>
                <tbody>
                <?php foreach ($audit as $log): ?>
                    <tr>
                        <td class="small muted"><?= e($log['created_at']) ?></td>
                        <td class="mono small"><?= e($log['action']) ?></td>
                        <td><?= status_badge($log['result']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$audit): ?><tr><td colspan="3" class="muted">暂无记录</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>