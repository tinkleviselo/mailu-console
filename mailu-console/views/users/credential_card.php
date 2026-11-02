<?php $ep = $card['endpoints']; ?>
<div class="credential-card">
    <h3>邮箱登录信息</h3>
    <table>
        <tr><th style="width:150px">邮箱账号</th><td class="mono"><?= e($card['email']) ?></td></tr>
        <tr><th>登录密码</th><td class="mono"><?= e($card['password'] !== '' ? $card['password'] : '（请向管理员索取）') ?></td></tr>
        <tr><th>网页邮箱</th><td class="mono"><?= e($ep['webmail']) ?></td></tr>
        <tr><th>收信 IMAP</th><td class="mono"><?= e($ep['imap']['host']) ?> : <?= (int) $ep['imap']['port'] ?>（<?= e(strtoupper($ep['imap']['security'])) ?>）</td></tr>
        <tr><th>收信 POP3</th><td class="mono"><?= e($ep['pop3']['host']) ?> : <?= (int) $ep['pop3']['port'] ?>（<?= e(strtoupper($ep['pop3']['security'])) ?>）</td></tr>
        <tr><th>发信 SMTP</th><td class="mono"><?= e($ep['smtp']['host']) ?> : <?= (int) $ep['smtp']['port'] ?>（<?= e(strtoupper($ep['smtp']['security'])) ?>）<?= !empty($ep['smtp']['alt_port']) ? '，备用 ' . (int) $ep['smtp']['alt_port'] . '（STARTTLS）' : '' ?></td></tr>
        <tr><th>Sieve 过滤器</th><td class="mono"><?= e($ep['sieve']['host']) ?> : <?= (int) $ep['sieve']['port'] ?></td></tr>
        <tr><th>配额</th><td><?= e($card['quota_mb']) ?> MB（已用 <?= e($card['used_mb']) ?> MB）<?= $card['enabled'] ? '' : ' · <strong>账号已禁用</strong>' ?></td></tr>
    </table>
    <p style="font-size:12px;color:#555;margin:10px 0 0">账号栏请填写完整邮箱地址；IMAP/POP3/SMTP 使用同一密码。若客户端提示证书错误，请确认服务器域名与证书一致。</p>
</div>