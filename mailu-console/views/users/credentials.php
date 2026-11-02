<h2><?= e($user['email']) ?> 登录信息</h2>
<?php partial('users/credential_card', array('card' => $card, 'user' => $user)); ?>
<div class="credential-card">
    <h3>客户端配置步骤</h3>
    <ol style="margin:0;padding-left:20px;line-height:1.9">
        <li>打开邮件客户端（Outlook / Foxmail / 手机自带邮件 / Thunderbird）</li>
        <li>选择“手动配置”或“其他账号 (IMAP)”</li>
        <li>账号：<span class="mono"><?= e($card['email']) ?></span>，密码：见上方</li>
        <li>IMAP 服务器：<span class="mono"><?= e($card['endpoints']['imap']['host']) ?></span>，端口 <span class="mono"><?= (int) $card['endpoints']['imap']['port'] ?></span>，加密 <?= e(strtoupper($card['endpoints']['imap']['security'])) ?></li>
        <li>SMTP 服务器：<span class="mono"><?= e($card['endpoints']['smtp']['host']) ?></span>，端口 <span class="mono"><?= (int) $card['endpoints']['smtp']['port'] ?></span>，加密 <?= e(strtoupper($card['endpoints']['smtp']['security'])) ?></li>
        <li>保存后即可收发邮件；也可以直接登录网页邮箱 <span class="mono"><?= e($card['endpoints']['webmail']) ?></span></li>
    </ol>
</div>