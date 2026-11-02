<?php
$nav = array(
    '概览' => array(
        array('/' , '总览', 'dashboard'),
    ),
    '邮件系统' => array(
        array('/servers', '邮件服务器', 'servers'),
        array('/domains', '域名管理', 'domains'),
        array('/users', '邮箱账号', 'users'),
        array('/aliases', '别名 / 转发', 'aliases'),
    ),
    '工具' => array(
        array('/tools/dns', 'DNS 与收信', 'tools'),
        array('/tools/probe', '收发登录测试', 'tools'),
        array('/tools/credentials', '登录信息汇总', 'tools'),
        array('/tools/import', 'CSV 批量导入', 'tools'),
    ),
    '运维' => array(
        array('/deploy', '部署中心', 'deploy'),
        array('/audit', '操作审计', 'audit'),
        array('/settings', '系统设置', 'settings'),
    ),
);
$active = isset($currentPath) ? $currentPath : '/';
?>
<aside class="sidebar">
    <div class="brand">
        <?= e($appName) ?>
        <small>多域名邮件管理平台 v<?= e($appVersion) ?></small>
    </div>
    <nav>
        <?php foreach ($nav as $group => $items): ?>
            <div class="group"><?= e($group) ?></div>
            <?php foreach ($items as $item): ?>
                <?php
                $path = $item[0];
                $isActive = ($path === '/' && $active === '/') || ($path !== '/' && strpos($active, $path) === 0);
                ?>
                <a href="<?= e(url($path)) ?>" class="<?= $isActive ? 'active' : '' ?>"><?= e($item[1]) ?></a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>
</aside>