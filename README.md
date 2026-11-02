# Mailu Console · 多域名邮件中台

[![ci](https://github.com/tinkleviselo/mailu-console/actions/workflows/ci.yml/badge.svg)](https://github.com/tinkleviselo/mailu-console/actions/workflows/ci.yml)
[![release](https://img.shields.io/github/v/release/tinkleviselo/mailu-console?display_name=tag)](https://github.com/tinkleviselo/mailu-console/releases)
[![license](https://img.shields.io/github/license/tinkleviselo/mailu-console)](LICENSE)
[![php](https://img.shields.io/badge/PHP-7.4%20~%208.4-777bb4)](https://www.php.net/)
[![mailu](https://img.shields.io/badge/Mailu-1.9%20%7C%202.x-2f7cf6)](https://mailu.io/)

一套围绕开源邮件系统 [Mailu](https://mailu.io) 的**多域名邮件运营中台** + **Linux 一键部署脚本**。

PHP 中台通过 Mailu 的 REST API 统一管理多台邮件服务器、多个域名和成百上千个邮箱账号；
部署脚本一条命令在主流 Linux 发行版上装好 Mailu（Docker Compose），并把管理员、API 令牌、
DKIM 密钥、防火墙都配好，最后可直接接入中台。

> **English summary** — A self-hosted, dependency-free PHP admin console for [Mailu](https://mailu.io)
> that manages multiple mail servers, domains and mailboxes over the Mailu REST API
> (accounts, aliases, quotas, password resets, login-instruction sheets, DNS checks, audit log),
> plus one-command Linux installers (Debian/Ubuntu, RHEL family, openSUSE, Alpine, Arch)
> with DNSSEC-aware Docker setup, TLS, firewall, backup/update/uninstall and batch fleet deployment.
> MIT licensed.

```
┌──────────────────────────┐      HTTPS + REST API /api/v1     ┌───────────────────────────┐
│  中台（PHP，零依赖）      │ ────────────────────────────────► │  Mailu 邮件服务器 A（VPS） │
│  · 多服务器 / 多域名      │   domain / user / alias / dkim     │  front · imap · smtp       │
│  · 账号与配额、重置密码    │                                   │  antispam · admin · webmail│
│  · 登录信息卡片 / 收发测试 │                                   ├───────────────────────────┤
│  · DNS 校验 / 审计 / API  │                                   │  Mailu 邮件服务器 B（VPS） │
└──────────────────────────┘                                    └───────────────────────────┘
```

## 特性

**中台（`mailu-console/`）**

| 模块 | 能力 |
|---|---|
| 服务器 | 多实例管理、API 令牌 AES-256-GCM 加密存储、连接与 TLS 证书检查、一键同步 |
| 域名 | 添加/删除、别名域名、账号数与配额上限、一键让 Mailu 生成 DKIM/DMARC |
| 账号 | 建号/改配额/启停/删除/重置密码、转发、垃圾邮件阈值、粘贴批量建号、CSV 导入导出 |
| 别名 | 一别名多目标、邮件组、从服务器反向同步 |
| 登录信息 | IMAP/POP3/SMTP/Webmail 卡片，导出 CSV / TXT / HTML / Mozilla autoconfig XML |
| 收发测试 | 真实握手 IMAP / POP3 / SMTP 验证账号能否收信发信（不发信） |
| DNS | MX / SPF / DKIM / DMARC 生成 + 在线校验（`dns_get_record`） |
| 部署中心 | 网页生成定制安装脚本、下载单文件脚本/部署包、可选 SSH 远程执行并留日志 |
| 运维 | 全量操作审计、系统设置、对外 JSON API（Bearer 令牌） |

**部署脚本（`deploy/`）**

- 兼容 Debian/Ubuntu、RHEL/CentOS/Rocky/Alma/Oracle/Fedora/Amazon Linux、openSUSE、Alpine、Arch
- 自动装 Docker + Compose → 下载 Mailu 官方安装包 → 生成 `docker-compose.yml` / `mailu.env`
- TLS（Let's Encrypt / 自签 / 宿主 certbot 续期钩子）、防火墙、内核参数
- 内置 unbound 解析器（Mailu admin 要求 DNSSEC）；不可用时自动回退到公共校验型 DNS
- `--dry-run` / `--status` / `--backup` / `--update` / `--uninstall` / `--extract-only`
- `mailu-ctl`：状态、日志、增删域名/账号/别名、生成 DKIM、九项一键体检 `doctor`
- `deploy/auto/`：从任意 HTTPS 制品站点一键安装（SHA256 校验 + 版本目录 + 回滚）、SSH 批量部署

## 快速开始

### 1. 部署中台

**方式 A：虚拟主机 / 宝塔（子目录，上传即用）**

```bash
# 下载 Release 里的 mailu-console-web-*.zip，上传到站点目录并解压
unzip mailu-console-web-1.0.0.zip -d /www/wwwroot/example.com/
mv /www/wwwroot/example.com/mailu-console-web /www/wwwroot/example.com/mailu
chmod -R 755 /www/wwwroot/example.com/mailu
chmod -R 775 /www/wwwroot/example.com/mailu/storage /www/wwwroot/example.com/mailu/config
# 打开 https://example.com/mailu/setup
```

**方式 B：独立域名（`public/` 作为站点根）**

```bash
git clone https://github.com/tinkleviselo/mailu-console.git && cd mailu-console
php -S 127.0.0.1:8080 -t mailu-console/public mailu-console/public/router.php
# 打开 http://127.0.0.1:8080/setup
```

要求：PHP 7.4–8.4 + `pdo_sqlite`（或 MySQL）+ `openssl` + `json` + `curl`。无需 Composer。

### 2. 部署邮件服务器

```bash
curl -fsSL https://raw.githubusercontent.com/tinkleviselo/mailu-console/main/deploy/auto/bootstrap.sh \
  | sudo bash -s -- \
      --pkg-url https://github.com/tinkleviselo/mailu-console/releases/latest/download/mailu-deploy-1.0.0.zip \
      --domain example.com --hostname mail.example.com --yes

# 或者克隆仓库后用本地脚本
sudo bash deploy/install.sh --domain example.com --hostname mail.example.com --yes
sudo bash deploy/bin/mailu-ctl doctor     # 九项体检，应全部 OK
```

安装完成会打印：后台地址、管理员账号密码、API 令牌、DNS 记录、客户端参数。

### 3. 接入中台

中台 → **邮件服务器 → 添加服务器**：

| 字段 | 值 |
|---|---|
| 访问地址 | `https://mail.example.com` |
| API 路径 | `/api/v1` |
| 认证方式 | API 令牌 |
| 令牌 | 服务器上 `grep '^API_TOKEN=' /mailu/mailu.env` |

保存 →「检查连接」→「同步」，即可管理域名、账号、别名与登录信息。

## 目录结构

```
mailu-console/            PHP 中台
├── app/Core/             框架：路由、配置、数据库、会话、CSRF、AES 加密、日志
├── app/Service/          MailuClient（REST 客户端）、账号/域名/别名、凭据、DNS、探针、部署
├── app/Controller/       后台控制器 + 对外 JSON API
├── views/                纯 PHP 视图（中文界面，无模板引擎、无 CDN 依赖）
├── public/               唯一应暴露到 Web 的目录（另有扁平版由 build/webvariant.py 生成）
├── docker/               Dockerfile / compose / Caddyfile / 自动初始化 entrypoint
└── resources/            内置单文件部署脚本（供「部署中心」生成定制脚本）

deploy/                   Linux 部署脚本
├── install.sh            入口：识别系统 → Docker → 安装包 → 配置 → TLS → 启动 → 初始化
├── lib/                  common / docker / mailu / tls / firewall / report
├── bin/                  mailu-ctl、备份、更新、卸载
├── auto/                 bootstrap（CDN 一键）、fleet-deploy（SSH 批量）
├── packages/             离线安装包获取
└── tests/selfcheck.sh    不需要 Docker/root 的配置生成自检

build/                    package.py（打包）、release.py（发布清单）、webvariant.py（扁平版）
docs/                     CONSOLE · DEPLOY · API · AUTO-DEPLOY · TROUBLESHOOTING
tests/                    mock Mailu API + 端到端冒烟测试
```

## 文档

| 文档 | 内容 |
|---|---|
| [docs/CONSOLE.md](docs/CONSOLE.md) | 中台安装（Apache/Nginx/Docker/虚拟主机）、多域名逻辑、备份迁移 |
| [docs/DEPLOY.md](docs/DEPLOY.md) | 邮件服务器部署、端口、DNS 记录、版本与镜像 |
| [docs/API.md](docs/API.md) | 中台对外 API 与 Mailu API 映射表 |
| [docs/AUTO-DEPLOY.md](docs/AUTO-DEPLOY.md) | 制品托管 + 一条命令上线、批量部署、CI/CD、安全与 Runbook |
| [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) | 五个真实案例：DNSSEC、限流、管理员权限、半成品库、bash `!` |

## 开发

```bash
make lint         # PHP 语法 + shellcheck
make selfcheck    # 部署脚本自检（无需 Docker/root）
make smoke        # 中台端到端（模拟 Mailu API）
make build        # 生成 dist/ 制品
```

详见 [CONTRIBUTING.md](CONTRIBUTING.md)。

## 路线图

- [ ] 中台首次登录向导（自动带出接入步骤、一键检查连接并同步）
- [ ] 域名批量导入（CSV：域名 + 管理员 + 配额）
- [ ] 邮件队列/投递日志可视化（读 Mailu `postqueue` / rspamd 统计）
- [ ] 多语言界面（当前为中文）
- [ ] 中台的 `curl | bash` 一键部署脚本

## 安全

中台会保存 Mailu API 令牌与部分邮箱密码，均以 AES-256-GCM 加密（密钥来自 `config.php` 的 `app.key`）；
管理员密码用 bcrypt；对外 API 只存令牌的 SHA-256 摘要。请务必用 HTTPS 暴露中台。
漏洞请按 [SECURITY.md](SECURITY.md) 私下报告。

## 许可证

[MIT](LICENSE) © 2026 TINKLEVISELO/DESIPO

Mailu 本身由其作者以 MIT 许可发布；本项目的部署脚本只调用官方镜像与安装包，不修改 Mailu 源码。

## 致谢

- [Mailu](https://mailu.io) —— 邮件服务器本体
- [SnappyMail](https://snappymail.eu/) / [Roundcube](https://roundcube.net/) —— 网页邮箱
- 所有提供 Issue 与 PR 的贡献者