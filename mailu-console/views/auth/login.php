<div class="card">
    <h1><?= e($appName) ?></h1>
    <p class="muted small">请使用中台管理员账号登录</p>
    <form method="post" action="<?= e(url('/login')) ?>" data-guard>
        <?= csrf_field() ?>
        <div class="field mb">
            <label>用户名</label>
            <input type="text" name="username" autocomplete="username" autofocus required>
        </div>
        <div class="field mb">
            <label>密码</label>
            <input type="password" name="password" autocomplete="current-password" required>
        </div>
        <button class="btn btn-primary" type="submit" data-busy="登录中...">登录</button>
    </form>
</div>
<p class="muted small">首次使用？访问 <a href="<?= e(url('/setup')) ?>">/setup</a> 完成初始化。</p>