<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(isset($title) ? $title : '总览') ?> - <?= e($appName) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
<div class="layout">
    <?php partial('partials/sidebar'); ?>
    <div class="main">
        <div class="topbar">
            <div>
                <strong><?= e(isset($title) ? $title : '') ?></strong>
            </div>
            <div class="btn-row">
                <form method="post" action="<?= e(url('/servers/sync-all')) ?>" style="display:inline">
                    <?= csrf_field() ?>
                    <button class="btn btn-sm" type="submit">同步全部服务器</button>
                </form>
                <span class="who"><?= e($adminUser ? $adminUser['username'] : '') ?></span>
                <form method="post" action="<?= e(url('/logout')) ?>" style="display:inline">
                    <?= csrf_field() ?>
                    <button class="btn btn-sm" type="submit">退出</button>
                </form>
            </div>
        </div>
        <div class="content">
            <?php partial('partials/flash'); ?>
            <?= $content ?>
        </div>
        <div class="footer">
            <?= e($appName) ?> v<?= e($appVersion) ?> · 基于 Mailu REST API 的多域名邮件中台 ·
            如需帮助请查看 docs/ 目录下的文档
        </div>
    </div>
</div>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>