# Mailu 一键部署脚本包（Linux VPS）

本目录是随 **Mailu 邮件中台** 一起分发的 Linux 部署脚本包。整套脚本只依赖 `bash`、
`curl`、`openssl` 与 Docker，用于在主流 Linux 发行版上一键安装 / 升级 / 备份 / 卸载
Mailu 邮件系统，并自动完成 API 令牌、管理员账号、DKIM 密钥与防火墙配置。

> 中台（PHP 版）在 `mailu-console/` 目录；中台的“部署中心”会基于本脚本包生成
> 带参数的定制安装脚本，可直接下载到 VPS 执行。

## 1. 目录结构

```
deploy/
├── install.sh                 主安装脚本（识别系统 → 装 Docker → 下载安装包 → 生成配置 → 启动 → 初始化）
├── lib/
│   ├── common.sh              日志、系统识别、包管理、下载、模板渲染
│   ├── docker.sh              Docker / Docker Compose 安装与检测
│   ├── mailu.sh               安装包下载、docker-compose.yml 与 mailu.env 生成、API 初始化、备份/更新/卸载
│   ├── tls.sh                 Let's Encrypt / 自签 / 宿主机 certbot 证书处理
│   ├── firewall.sh            ufw / firewalld / iptables / nftables 端口放行 + 内核参数
│   └── report.sh              安装结果汇总（DNS 记录、客户端设置、中台接入信息）
├── bin/
│   ├── mailu-ctl              运维与管理命令行（增删域名/账号/别名、查日志、看状态、生成 DKIM）
│   ├── mailu-backup.sh        数据备份（默认保留 7 份）
│   ├── mailu-update.sh        镜像更新（更新前自动备份）
│   └── mailu-uninstall.sh     停止/卸载（--purge 连数据一起删除）
├── packages/
│   └── fetch-package.sh       下载 Mailu 官方安装包到本地缓存（离线部署用）
├── auto/
│   ├── bootstrap.sh           引导安装器：从 CDN 下载安装包 -> SHA256 校验 -> 解压 -> 调用 install.sh
│   └── fleet-deploy.sh        舰队批量部署：读节点清单，SSH 并发部署多台 VPS
├── tests/
│   └── selfcheck.sh           自检脚本：生成配置到临时目录并校验 29 项（可在任意 Linux 上运行）
└── templates/
    └── mailu-deploy.conf.example  参数文件示例（安装后写入 /etc/mailu-deploy/mailu-deploy.conf）
```

单文件版本 `mailu-deploy.bundle.sh` 由 `build/package.py` 生成（所有 lib 合并进一个脚本），
中台生成的定制脚本就是在这个单文件基础上替换参数块得到的。

## 2. 支持的系统

| 系统家族 | 版本示例 | 包管理器 | 说明 |
|---|---|---|---|
| Debian / Ubuntu | Debian 10-13、Ubuntu 18.04-24.04、Raspbian、Deepin、UOS、Kylin | apt | 首选发行版仓库，失败回退官方脚本 |
| RHEL 系 | CentOS 7-9、Rocky 8/9、AlmaLinux 8/9、Oracle Linux、Fedora、Amazon Linux 2/2023 | dnf / yum | 自动安装 dnf-plugins-core |
| openSUSE | Leap 15.x、Tumbleweed、SLES | zypper | 使用 docker + docker-compose |
| Alpine | 3.16+ | apk | 需要先 `apk add bash curl`（Alpine 默认无 bash） |
| Arch | Arch、Manjaro | pacman | docker + docker-compose |

要求：root 权限、x86_64 或 arm64、至少 2 核 2GB 内存（启用 ClamAV 建议 4GB）、
25 端口未被占用（多数云厂商默认封 25 端口，需要提交工单解封才能对外收信）。

## 3. 快速开始

```bash
# 1) 上传脚本包并解压
unzip mailu-deploy-1.0.0.zip && cd mailu-deploy

# 2) 一键安装（推荐先加 --dry-run 预演）
sudo bash install.sh \
  --domain example.com \
  --hostname mail.example.com \
  --admin-user admin \
  --tls letsencrypt \
  --yes

# 3) 查看状态 / 管理
sudo bash bin/mailu-ctl status
sudo bash bin/mailu-ctl doctor      # 一键体检：容器/8080/限流/API/webmail/DNS/DNSSEC/DKIM/证书
sudo bash bin/mailu-ctl add-domain example.net
sudo bash bin/mailu-ctl add-user zhangsan example.com 'Passw0rd!23' 2048
sudo bash bin/mailu-ctl dkim example.com      # 生成并输出 DKIM TXT 记录
sudo bash bin/mailu-ctl dns example.com       # 输出全部需要的 DNS 记录
```

安装完成后脚本会打印：后台地址、管理员账号密码、API 令牌、IMAP/POP3/SMTP 参数、
DNS 记录清单，以及如何把该服务器接入邮件中台。

## 3.5 从 CDN 一键安装（全局自动部署）

把制品上传到任意 HTTPS 站点后，VPS 上只需一条命令：

```bash
curl -fsSL https://cdn.example.com/mailu/install.sh | sudo bash -s -- \
  --domain example.com --hostname mail.example.com --yes
```

引导器的能力：

* 读 `latest.json` 解析版本与 SHA256，下载后**强制校验**；
* 安装包按版本解压到 `/opt/mailu-deploy/releases/<version>`，`current` 软链接指向生效版本；
* 支持 `--version` / `--pkg-url` / `--pkg-sha256` / `--base-url` 覆盖；
* `--upgrade` 升级（自动备份）、`--rollback` 回滚、`--list` 查看本机版本；
* 除引导参数外，其余参数原样转发给 `install.sh`。

多机批量：

```bash
bash auto/fleet-deploy.sh --nodes auto/fleet.nodes --parallel 4 \
     --base-url https://cdn.example.com/mailu --yes --report result.csv
```

完整方案见 [../docs/AUTO-DEPLOY.md](../docs/AUTO-DEPLOY.md)。

## 4. 常用参数

| 参数 | 说明 |
|---|---|
| `--domain` | 主邮件域名（必填） |
| `--hostname` | 邮件主机名，多个用逗号分隔（必填，需已解析到本机） |
| `--tls` | `letsencrypt`（默认，Mailu 内置自动申请）、`certbot`（宿主机 certbot+续期钩子）、`cert`（自签/自有证书）、`notls`（仅测试） |
| `--admin-user` / `--admin-pass` | Mailu 全局管理员（默认 admin / 随机 18 位） |
| `--api-token` | REST API 令牌（默认随机生成；中台对接必需，`--no-api` 可关闭 API） |
| `--webmail` | `snappymail`（默认）/ `roundcube` / `none`（Mailu 2.x 统一使用 `mailu/webmail` 镜像，由该变量选择） |
| `--webmail-image` | 覆盖 webmail 镜像名（自建镜像仓库时使用） |
| `--version` | Mailu 版本标签，默认 `2024.06.61` |
| `--mirror-prefix` | GitHub 加速前缀，例如 `https://ghproxy.net/`（国内 VPS 推荐） |
| `--docker-org` | 镜像前缀，默认 `ghcr.io/mailu`（可换国内镜像仓库） |
| `--root` / `--subnet` | 安装目录（默认 `/mailu`）与容器网段（默认 `192.168.203.0/24`） |
| `--no-docker` / `--no-firewall` | 跳过 Docker 安装 / 防火墙配置 |
| `--firewall` | `auto`（默认）/`ufw`/`firewalld`/`iptables`/`nftables`/`none` |
| `--antivirus clamav` | 启用 ClamAV（内存占用大） |
| `--dry-run` | 只打印将执行的动作，不做任何修改 |
| `--yes` | 非交互模式 |

动作型参数：`--status`、`--backup`、`--restore FILE`、`--update [--new-version X]`、
`--reconfigure`（按当前参数重新生成配置并重启）、`--uninstall [--purge]`。

## 5. 脚本做了什么

1. 识别发行版/架构，安装 `curl`、`openssl`、`ca-certificates`；
2. 安装 Docker Engine + Compose 插件（发行版仓库优先，失败回退 `get.docker.com`）；
3. 创建 `/mailu` 数据目录树（certs、data、dkim、mail、mailqueue、filter、redis、webmail、overrides）；
4. 下载 Mailu 官方安装包（GitHub release tarball，支持镜像加速与本地缓存），
   并把官方模板保存到 `.cache/` 便于比对；
5. 生成 `docker-compose.yml`（按参数裁剪服务：front/imap/smtp/antispam/admin/webmail/可选 clamav、radicale、fetchmail）
   与 `mailu.env`（含 SECRET_KEY、DOMAIN、HOSTNAMES、TLS_FLAVOR、API_TOKEN 等，权限 600）；
6. 准备 TLS：Let's Encrypt（内置 certbot）或自签证书（825 天，含 SAN），certbot 模式会安装
   续期钩子自动同步证书并重启 front；
7. 放行 25/80/110/143/443/465/587/993/995/4190 端口并写入内核参数 `/etc/sysctl.d/99-mailu.conf`；
8. `docker compose pull && up -d`，等待容器就绪；
9. 通过 REST API 创建主域名、全局管理员、生成 DKIM 密钥（API 不可用时回退 `flask mailu` 命令行）；
10. 保存参数到 `/etc/mailu-deploy/mailu-deploy.conf`，输出汇总报告与 DNS/客户端配置。

脚本是幂等的：重复执行只会重建配置并重启容器，不会删除数据；`mailu.env` 每次修改前会备份。

## 6. 离线 / 内网部署

```bash
# 有网络的机器上先取安装包
bash packages/fetch-package.sh 2024.06.61 --mirror-prefix https://ghproxy.net/
# 把整个 deploy 目录（含 packages/cache）拷贝到目标机
sudo bash install.sh --domain example.com --hostname mail.example.com --yes
```

安装包缓存位于 `packages/cache/mailu-<版本>.tar.gz`，脚本会优先使用本地缓存；
Docker 镜像可在有网络的机器上 `docker save` 后 `docker load` 导入。

## 7. 备份 / 升级 / 卸载

```bash
sudo bash bin/mailu-backup.sh                  # 备份到 /var/backups/mailu，自动保留 7 份
sudo bash bin/mailu-backup.sh --with-mail      # 连邮件正文一起备份（体积大）
sudo bash bin/mailu-update.sh                  # 更新镜像（先自动备份）
sudo bash bin/mailu-update.sh --new-version 2024.06.61
sudo bash bin/mailu-uninstall.sh               # 停止并删除容器，保留数据
sudo bash bin/mailu-uninstall.sh --purge       # 连数据目录一起删除（不可恢复）
```

## 8. 安装后必须做的事

1. **DNS**：添加 MX、SPF、DKIM、DMARC 四条记录（用 `mailu-ctl dns <域名>` 导出），
   以及 `mail.<域名>` 的 A 记录。DKIM 记录需先执行 `mailu-ctl dkim <域名>` 生成密钥。
2. **PTR / rDNS**：在云服务商处把服务器 IP 的反向解析设置为 `mail.<域名>`，否则发信容易被拒。
3. **端口**：确认云安全组放行 25、80、443、465、587、993、995；阿里云/腾讯云/AWS 默认封 25 端口。
4. **中台接入**：在中台“邮件服务器 → 添加服务器”里填入 `https://mail.<域名>` 与 API 令牌，
   点击“检查连接”与“同步”。

## 8.1 自检（可选）

在正式安装前，可以在任意 Linux 机器上运行自检脚本，它会把 `docker-compose.yml`、`mailu.env`
生成到临时目录并逐项校验（不会安装任何东西、不修改系统）：

```bash
sudo TEST_ROOT=/tmp/mailu-selfcheck bash tests/selfcheck.sh
# 结果: PASS=29 FAIL=0 SKIP=3（SKIP 为缺少 openssl 时的证书项）
```

自检覆盖：参数解析、镜像命名（2.x 与 1.x 两套）、compose 服务与端口、mailu.env 关键项、
随机数/JSON/模板渲染等基础函数。示例产物见 `docs/examples/`。

## 9. 故障排查

| 现象 | 排查方向 |
|---|---|
| 容器起不来 | `cd /mailu && docker compose logs --tail=100`；常见原因是 25/80/443 端口被占用（`ss -lntp`） |
| 访问 https 证书错误 | Let's Encrypt 需要 80 端口公网可达；查看 `docker compose logs front`；或改用 `--tls cert` 自签 |
| 收不到邮件 | MX 是否生效（`dig MX 域名`）、云安全组是否放行 25、`docker compose logs smtp` |
| 发出的邮件进垃圾箱 | 检查 SPF/DKIM/DMARC、rDNS、IP 是否在黑名单（`mailu-ctl dkim`） |
| 中台提示 API 401 | `mailu.env` 中 `API=true`、`API_TOKEN` 与中台填写的一致，改后需 `docker compose up -d` |
| 拉取镜像慢 | `--docker-org` 换成国内镜像仓库，或先 `docker pull` 后 `docker save/load` |
| 磁盘爆满 | `mailu-ctl logs`、清理 `/var/lib/docker`、备份后清理旧邮件；`docker system prune` |

日志文件：`/var/log/mailu-deploy.log`；参数文件：`/etc/mailu-deploy/mailu-deploy.conf`。