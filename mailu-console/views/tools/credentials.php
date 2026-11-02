<div class="card">
    <div class="flex-between">
        <h2>登录信息汇总</h2>
        <div class="btn-row">
            <form method="post" action="<?= e(url('/tools/credentials/export')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="server_id" value="<?= (int) $filters['server_id'] ?>">
                <input type="hidden" name="domain" value="<?= e($filters['domain']) ?>">
                <input type="hidden" name="q" value="<?= e($filters['q']) ?>">
                <input type="hidden" name="format" value="csv">
                <button class="btn" type="submit">导出 CSV（含密码）</button>
            </form>
            <form method="post" action="<?= e(url('/tools/credentials/export')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="server_id" value="<?= (int) $filters['server_id'] ?>">
                <input type="hidden" name="domain" value="<?= e($filters['domain']) ?>">
                <input type="hidden" name="format" value="txt">
                <button class="btn" type="submit">导出 TXT</button>
            </form>
        </div>
    </div>
    <form method="get" action="<?= e(url('/tools/credentials')) ?>" class="row">
        <div class="field">
            <label>服务器</label>
            <select name="server_id">
                <option value="">全部</option>
                <?php foreach ($servers as $server): ?>
                    <option value="<?= (int) $server['id'] ?>" <?= (int) $filters['server_id'] === (int) $server['id'] ? 'selected' : '' ?>><?= e($server['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>域名</label>
            <select name="domain">
                <option value="">全部</option>
                <?php foreach ($domains as $domain): ?>
                    <option value="<?= e($domain['name']) ?>" <?= $filters['domain'] === $domain['name'] ? 'selected' : '' ?>><?= e($domain['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field"><label>搜索</label><input type="text" name="q" value="<?= e($filters['q']) ?>"></div>
        <div class="field">
            <label>密码显示</label>
            <div class="checkbox"><input type="checkbox" name="reveal" value="1" id="reveal" <?= !empty($reveal) ? 'checked' : '' ?>><label for="reveal" style="margin:0">显示明文密码</label></div>
        </div>
        <div class="field" style="flex:0 0 auto"><label>&nbsp;</label><button class="btn" type="submit">筛选</button></div>
    </form>
    <p class="muted small">仅显示通过中台创建或重置过密码的账号可看到明文密码；历史账号密码未知，请使用“重置密码”。当前共 <?= count($cards) ?> 个账号。</p>
    <div class="table-wrap">
        <table>
            <thead><tr><th>账号</th><th>密码</th><th>服务器</th><th>IMAP</th><th>SMTP</th><th>网页邮箱</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($cards as $card): ?>
                <tr>
                    <td class="mono small"><?= e($card['email']) ?></td>
                    <td class="mono small">
                        <?php if ($card['password'] !== ''): ?>
                            <span class="copyable" data-copy="<?= e($card['password']) ?>" title="点击复制"><?= e($card['password']) ?></span>
                        <?php else: ?>
                            <span class="badge badge-muted">未知</span>
                        <?php endif; ?>
                    </td>
                    <td class="small muted"><?= e($card['server']) ?></td>
                    <td class="mono small"><?= e($card['endpoints']['imap']['host']) ?>:<?= (int) $card['endpoints']['imap']['port'] ?></td>
                    <td class="mono small"><?= e($card['endpoints']['smtp']['host']) ?>:<?= (int) $card['endpoints']['smtp']['port'] ?></td>
                    <td class="small mono"><?= e($card['endpoints']['webmail']) ?></td>
                    <td><a class="btn btn-sm" href="<?= e(url('/tools/credentials')) ?>#">—</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$cards): ?><tr><td colspan="7" class="muted">没有匹配的账号</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>