# 中台部署与使用说明

## 1. 环境要求

| 项目 | 要求 |
|---|---|
| PHP | 7.4 / 8.0 / 8.1 / 8.2 / 8.3 / 8.4 |
| 必装扩展 | `pdo` + （`pdo_sqlite` 或 `pdo_mysql`）、`openssl`、`json`、`curl`（或 `allow_url_fopen=On`） |
| 可选扩展 | `zip`（部署包 ZIP 下载）、`mbstring`（中文截断更准确）、`fileinfo` |
| 数据库 | SQLite（默认，零配置）或 MySQL/MariaDB 5.7+ |
| Web 服务器 | Apache 2.4、Nginx、Caddy、IIS 均可；网站根目录指向 `public/` |
| 磁盘 | 建议 ≥ 200MB（含日志与生成的脚本） |

不需要 Composer、不需要 Node.js，全部为纯 PHP 代码。

## 2. 安装

### 方式 A：传统主机（推荐）

```bash
unzip mailu-console-1.0.0.zip -d /var/www/
cd /var/www/mailu-console
chmod -R 750 storage config
chown -R www-data:www-data storage config
```

Apache（`DocumentRoot` 指向 `public/`，并允许 `.htaccess`）：

```apache
<VirtualHost *:80>
    ServerName console.example.com
    DocumentRoot /var/www/mailu-console/public
    <Directory /var/www/mailu-console/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Nginx：直接使用 `public/nginx.conf.sample`（修改 `server_name` 与 php-fpm socket 路径即可）。

然后浏览器访问 `http://你的地址/setup`，安装向导会：

1. 检查 PHP 版本与扩展；
2. 让你填写中台名称、管理员账号密码、数据库（SQLite/MySQL）、默认 Mailu 版本；
3. 生成 `config/config.php`（含随机 `app.key`）、初始化数据表、创建管理员；
4. 生成中台 API 令牌（会显示一次，可在设置页重新生成）。

> 安全提示：把 `config/`、`storage/`、`app/`、`views/` 放在 Web 根之外最安全；
> 若只能放在一起，Apache 的 `.htaccess` 与 Nginx 示例已内置拒绝访问规则。

### 方式 B：Docker

```bash
cd mailu-console/docker
docker compose up -d            # 访问 http://服务器IP:8080/setup
```

`docker/compose.yaml` 会把 `storage/`、`config/` 挂载到宿主机，便于备份。

### 方式 C：PHP 内置服务器（临时验证）

```bash
php -S 127.0.0.1:8080 -t public public/router.php
```

## 3. 第一次使用流程

1. **添加邮件服务器**：邮件服务器 → 添加服务器
   * 访问地址：`https://mail.example.com`（Mailu 对外地址，不要带 /admin）
   * API 路径：`/api/v1`
   * 认证方式：API 令牌（推荐）→ 粘贴部署脚本输出的 `API_TOKEN`
   * 收发服务器：留空会自动使用访问地址的主机名与默认端口（IMAP 993/POP3 995/SMTP 465）
   * 保存后点“检查连接”，成功即表示中台可以管理该服务器
2. **同步**：点击服务器详情页的“同步域名/账号/别名”，把远端数据拉到中台（之后每次改动都会实时写远端）。
3. **添加域名**：域名管理 → 添加域名（会同时在 Mailu 上创建）。
4. **创建账号**：邮箱账号 → 新建账号（或“批量创建”粘贴 `前缀,密码,配额MB,姓名,备注` 列表）。
5. **生成登录信息**：账号详情页的“登录信息”卡片可直接打印/导出 CSV、TXT；
   也可在“登录信息汇总”里按服务器/域名批量导出，交给客服或用户。
6. **DNS 与发信**：域名详情页或“DNS 与收信”页面会给出 MX/SPF/DKIM/DMARC 记录并在线校验；
   DKIM 需要先点“生成 DKIM 密钥”。
7. **收发验证**：“收发登录测试”可以直接用 IMAP/SMTP 握手验证账号能否登录与发信。

## 4. 多域名 / 多服务器逻辑

* 中台按 **服务器 → 域名 → 账号** 三级组织数据，本地 SQLite 只做镜像与检索，
  写操作一律先调用 Mailu API，成功后回写本地。
* 同一邮箱地址不能跨服务器重复；同一服务器上不同域名可以有相同前缀（Mailu 支持，地址不同）。
* 同步策略：以远端为准。远端不存在的对象会标记 `服务器上已删除`（`remote_exists=0`），
  账号列表会出现红色提示，可在中台删除或重新创建。
* 账号密码：中台只在“通过中台创建/重置过密码”的账号上保存密码（AES-256-GCM 加密），
  历史账号显示“未知”，需要“重置密码”后才能导出登录信息。

## 5. 备份与迁移

* SQLite：备份 `storage/mailu.sqlite`（含 `-wal`、`-shm`）与 `config/config.php`；
* MySQL：`mysqldump mailu_console > console.sql` + `config/config.php`；
* 迁移：把上述文件放到新主机的相同位置即可；`app.key` 必须保留，否则令牌与密码无法解密。

## 6. 邮件服务器端（Linux VPS）

中台的“部署中心”会生成定制安装脚本；也可以直接使用 `deploy/` 目录中的脚本包：

```bash
sudo bash deploy/install.sh --domain example.com --hostname mail.example.com --yes
```

完整参数、离线安装、升级/备份/卸载与故障排查请见 [deploy/README.md](../deploy/README.md)
或 [docs/DEPLOY.md](DEPLOY.md)。

## 7. 配置项参考（config/config.php）

```php
'app'  => ['name' => 'Mailu 邮件中台', 'key' => '<加密密钥，勿泄露>', 'base_path' => '', 'timezone' => 'Asia/Shanghai'],
'db'   => ['driver' => 'sqlite', 'database' => 'mailu.sqlite', 'prefix' => 'mu_'],
'mailu'=> ['api_path' => '/api/v1', 'timeout' => 20, 'default_quota_mb' => 2048, 'sync_user_limit' => 2000],
'deploy'=> ['mailu_version' => '2024.06.61', 'mirror_prefix' => '', 'allow_remote_deploy' => false],
```

* `deploy.allow_remote_deploy = true` 才会在部署中心出现“通过 SSH 执行”按钮，
  且运行中台的服务器上必须有 `ssh` 并且已配置免密登录目标 VPS；
* `app.base_path` 用于中台部署在子目录时（例如 `/console`）。

## 8. 常见问题

| 现象 | 处理 |
|---|---|
| 打开首页跳到 `/setup` | 说明还没初始化或 `config/config.php` 缺失 |
| 提示“数据库连接失败” | 检查 SQLite 的 `storage/` 写权限，或 MySQL 账号密码/库名 |
| 添加服务器提示 401/403 | Mailu 的 `mailu.env` 需 `API=true` 且 `API_TOKEN` 与中台一致，改完 `docker compose up -d` |
| 同步很慢 | 账号很多时 Mailu 逐个返回详情；可在 `config.php` 调整 `mailu.sync_user_limit` |
| 看不到明文密码 | 该账号不是中台创建的；用“重置密码”生成新密码 |
| DNS 校验不可用 | PHP 未启用 `dns_get_record()`（DNS 扩展），记录仍可复制到 DNS 服务商处手动添加 |
| 部署中心提示模板缺失 | `mailu-console/resources/mailu-deploy.bundle.sh` 不存在，请使用完整安装包或运行构建脚本 |