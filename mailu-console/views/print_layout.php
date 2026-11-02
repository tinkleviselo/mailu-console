<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(isset($title) ? $title : '登录信息') ?></title>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<style>
body { background: #f3f5f8; }
.content { max-width: 900px; margin: 0 auto; }
@media print { .no-print { display: none; } body { background: #fff; } }
</style>
</head>
<body>
<div class="content">
    <div class="no-print btn-row mb">
        <button class="btn btn-primary" onclick="window.print()">打印 / 导出 PDF</button>
        <a class="btn" href="<?= e(url('/users')) ?>">返回账号列表</a>
    </div>
    <?= $content ?>
</div>
</body>
</html>