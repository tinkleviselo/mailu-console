#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
生成「扁平版」中台：适合虚拟主机 / 宝塔 / 任何不能改 DocumentRoot 的环境。
解压后把整个目录上传到站点目录即可，index.php 在根上，自动识别 /mailu 这类子路径。

产物：
  dist/mailu-console-web-<版本>.zip
  .tools/console-web/            （解压副本，供本地测试）
"""
import hashlib
import os
import re
import shutil
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, 'mailu-console')
DIST = os.path.join(ROOT, 'dist')
STAGE = os.path.join(ROOT, '.tools', 'console-web')
VERSION = '1.0.0'

DENY = """# 拒绝直接访问该目录（Apache 2.4 / 2.2 双兼容）
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>
"""

ROOT_HTACCESS = """# Mailu 邮件中台 - 扁平版（站点根目录直接放本目录）
<IfModule mod_rewrite.c>
    RewriteEngine On
    # 已存在的文件/目录直接返回（assets 等静态资源）
    RewriteCond %{REQUEST_FILENAME} -f [OR]
    RewriteCond %{REQUEST_FILENAME} -d
    RewriteRule ^ - [L]
    # 其余全部交给前端控制器
    RewriteRule ^ index.php [L]
</IfModule>

# 敏感文件一律拒绝
<FilesMatch "\\.(sqlite|sqlite3|db|log|sh|zip|tar|gz|bak|ini|md)$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order deny,allow
        Deny from all
    </IfModule>
</FilesMatch>

<IfModule mod_headers.c>
    Header set X-Content-Type-Options "nosniff"
    Header set X-Frame-Options "SAMEORIGIN"
</IfModule>
"""

INDEX_PHP = """<?php
/**
 * Mailu 邮件中台 - 扁平版前端控制器（站点根目录直接可访问）
 */

$router = require __DIR__ . '/app/bootstrap.php';
$router->dispatch();
"""

ROUTER_PHP = """<?php
/**
 * PHP 内置服务器路由（本地测试用）：
 *   php -S 127.0.0.1:8080 -t . router.php
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$blocked = array('/app/', '/views/', '/config/', '/storage/', '/resources/');
foreach ($blocked as $dir) {
    if (strpos($path, $dir) === 0) {
        http_response_code(404);
        echo 'Not Found';
        return true;
    }
}
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}
require __DIR__ . '/index.php';
"""

WEB_README = """# Mailu 邮件中台（扁平版 · 上传即用）

适合虚拟主机 / 宝塔面板 / 任何无法修改网站根目录的环境。

## 上传步骤

1. 把本目录**全部内容**上传到站点目录，例如：
   - 独立域名：`/www/wwwroot/console.example.com/`
   - 子目录：`/www/wwwroot/example.com/mailu/`
2. 设置权限（Linux 主机）：
   ```bash
   chmod -R 755 .
   chmod -R 775 storage config
   chown -R www:www storage config      # 宝塔是 www；Ubuntu 是 www-data
   ```
3. 浏览器打开：
   - 独立域名 → `https://console.example.com/setup`
   - 子目录   → `https://example.com/mailu/setup`
4. 按向导填写：站点名称、管理员账号密码、数据库（默认 SQLite，免配置）。
5. 完成后用管理员账号登录，进入「邮件服务器 → 添加服务器」。

## 环境要求

- PHP 7.4 - 8.4，扩展：`pdo_sqlite`（或 `pdo_mysql`）、`openssl`、`json`、`curl`（或 `allow_url_fopen=On`）
- 可选：`zip`（部署包 ZIP 下载）、`mbstring`
- 不需要 Composer、不需要数据库服务（默认 SQLite）

## 目录说明

| 目录 | 作用 | 是否需要可写 |
|---|---|---|
| `storage/` | SQLite 数据库、日志、生成的部署脚本 | **是** |
| `config/` | 配置文件（安装向导生成 config.php） | **是** |
| `app/` `views/` `resources/` `assets/` | 程序代码与静态资源 | 否 |

`app/` `views/` `config/` `storage/` `resources/` 已内置 `.htaccess` 拒绝直接访问。

## 接入邮件服务器

「邮件服务器 → 添加服务器」：

| 字段 | 值 |
|---|---|
| 访问地址 | `https://mail.你的域名` |
| API 路径 | `/api/v1` |
| 认证方式 | API 令牌 |
| API 令牌 | 邮件服务器上 `grep '^API_TOKEN=' /mailu/mailu.env` 的值 |

保存 → 「检查连接」→「同步」，即可管理域名与账号。

## 备份

备份 `storage/`（含 SQLite）与 `config/config.php` 即可；`app.key` 在 config 里，丢失后已加密的令牌/密码无法解密。
"""


def write(path, content):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, 'w', encoding='utf-8', newline='\n') as fh:
        fh.write(content)


def main():
    if os.path.isdir(STAGE):
        shutil.rmtree(STAGE)
    os.makedirs(STAGE)

    # 1) 程序目录原样复制
    for name in ('app', 'views', 'resources'):
        shutil.copytree(os.path.join(SRC, name), os.path.join(STAGE, name),
                        ignore=shutil.ignore_patterns('.gitignore', '*.sqlite*', 'schema.lock'))
    # 2) 静态资源
    shutil.copytree(os.path.join(SRC, 'public', 'assets'), os.path.join(STAGE, 'assets'))
    # 3) config / storage 骨架
    os.makedirs(os.path.join(STAGE, 'config'))
    write(os.path.join(STAGE, 'config', 'config.example.php'),
          open(os.path.join(SRC, 'config', 'config.example.php'), encoding='utf-8').read())
    for sub in ('storage', 'storage/logs', 'storage/cache', 'storage/deploy'):
        os.makedirs(os.path.join(STAGE, sub), exist_ok=True)
    for sub in ('storage', 'storage/logs', 'storage/cache', 'storage/deploy'):
        write(os.path.join(STAGE, sub, '.gitignore'), '*\n!.gitignore\n!.htaccess\n')
    # 4) 根文件
    write(os.path.join(STAGE, 'index.php'), INDEX_PHP)
    write(os.path.join(STAGE, '.htaccess'), ROOT_HTACCESS)
    write(os.path.join(STAGE, 'router.php'), ROUTER_PHP)
    write(os.path.join(STAGE, 'README.md'), WEB_README)
    shutil.copyfile(os.path.join(SRC, 'public', 'healthz.php'), os.path.join(STAGE, 'healthz.php'))
    # 5) 受保护目录的 .htaccess
    for sub in ('app', 'views', 'config', 'storage', 'resources'):
        write(os.path.join(STAGE, sub, '.htaccess'), DENY)

    # 6) 打包
    os.makedirs(DIST, exist_ok=True)
    zip_path = os.path.join(DIST, 'mailu-console-web-%s.zip' % VERSION)
    with zipfile.ZipFile(zip_path, 'w', zipfile.ZIP_DEFLATED) as zf:
        for base, dirs, files in os.walk(STAGE):
            dirs[:] = [d for d in dirs if d not in ('.git', '__pycache__')]
            for name in files:
                full = os.path.join(base, name)
                rel = os.path.relpath(full, STAGE).replace('\\', '/')
                if name.endswith(('.sqlite', '.sqlite-wal', '.sqlite-shm')):
                    continue
                zf.write(full, 'mailu-console-web/' + rel)
    digest = hashlib.sha256(open(zip_path, 'rb').read()).hexdigest()
    with open(zip_path + '.sha256', 'w', encoding='utf-8', newline='\n') as fh:
        fh.write('%s  %s\n' % (digest, os.path.basename(zip_path)))
    size = os.path.getsize(zip_path)
    print('扁平版中台: %s (%.1f KB, %d 文件)' % (zip_path, size / 1024.0,
          len(zipfile.ZipFile(zip_path).namelist())))
    print('sha256:', digest)
    print('测试目录:', STAGE)


if __name__ == '__main__':
    main()