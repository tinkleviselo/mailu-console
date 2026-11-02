# 全局自动部署方案（制品托管 + 一键上线）

> 目标：把构建包放到任意网站（对象存储 / CDN / GitHub Releases），
> **只改一个 URL**，就能在任意 Linux VPS 上用一条命令全自动部署 Mailu 邮件系统；
> 同时支持中台自身的容器化自动上线，以及几十台 VPS 的批量（舰队）部署。

---

## 1. 总体架构

```
        ┌──────────────────────────────────────────────────────┐
        │  构建机 / CI                                          │
        │  python3 build/release.py --base-url https://cdn.x/mailu
        │    ├─ 产出 mailu-platform-1.0.0.zip（含中台+脚本+文档）│
        │    ├─ 产出 install.sh（已注入 base_url 的引导脚本）     │
        │    └─ 产出 latest.json（版本 + 各包 URL + SHA256）      │
        └───────────────────────────┬──────────────────────────┘
                                    │ 上传（ossutil / aws s3 / rclone / gh release）
                                    ▼
        ┌──────────────────────────────────────────────────────┐
        │  制品站点（对象存储 + CDN，HTTPS）                     │
        │  https://cdn.example.com/mailu/                       │
        │    ├─ install.sh          ← 唯一入口                   │
        │    ├─ latest.json         ← 版本与校验清单             │
        │    ├─ mailu-platform-1.0.0.zip                        │
        │    ├─ mailu-console-1.0.0.zip                         │
        │    ├─ mailu-deploy-1.0.0.zip                          │
        │    └─ mailu-deploy.bundle.sh                          │
        └───────────────────────────┬──────────────────────────┘
              ┌─────────────────────┼─────────────────────┐
              ▼                     ▼                     ▼
   ┌──────────────────┐  ┌──────────────────┐  ┌────────────────────┐
   │ 模式 A 单机一键   │  │ 模式 B 舰队批量   │  │ 模式 C 中台容器化   │
   │ curl | bash      │  │ fleet-deploy.sh  │  │ docker compose      │
   │ 任意 VPS 一条命令 │  │ N 台 VPS 并发执行 │  │ 自动 HTTPS + 自动初始化│
   └──────────────────┘  └──────────────────┘  └────────────────────┘
```

要点：

| 关注点 | 做法 |
|---|---|
| 唯一需要改的变量 | `base_url`（制品根地址）。改一处 → 重新生成 `install.sh` + `latest.json` |
| 版本可追溯 | `latest.json` 固定每次发布的 zip 文件名 + SHA256 |
| 防篡改 | 引导脚本强制校验 SHA256，不匹配立即中止 |
| 幂等 | 版本目录 + `current` 软链接；重复执行只重建配置、不删数据 |
| 可回滚 | 脚本包按版本目录保存，`--rollback` 一条命令切回 |
| 无人值守 | 全部参数支持命令行/环境变量，`--yes` 非交互 |
| 中台自动上线 | 容器首次启动读环境变量自动建库、建管理员、生成 API 令牌 |

---

## 2. 涉及的文件

| 路径 | 作用 |
|---|---|
| `deploy/auto/bootstrap.sh` | 引导安装器：下载 → 校验 → 解压 → 调用 `deploy/install.sh` |
| `deploy/auto/fleet-deploy.sh` | 舰队批量部署器：读节点清单，SSH 并发执行 |
| `deploy/auto/fleet.nodes.example` | 节点清单模板 |
| `build/release.py` | 发布器：构建制品 + 注入 URL + 生成 `latest.json` / `install.sh` |
| `mailu-console/app/cli/install.php` | 中台命令行安装器（容器/CI 无人值守初始化） |
| `mailu-console/docker/entrypoint.sh` | 容器入口：首次启动自动初始化 |
| `mailu-console/docker/compose.prod.yaml` | 生产编排：中台 + Caddy（自动 HTTPS）+ 健康检查 |
| `mailu-console/public/healthz.php` | 探活端点（容器 healthcheck / 负载均衡） |

---

## 3. 改 URL 的三种方式（任选其一）

### 方式一（推荐）：发布时注入，只需要改一个地方

```bash
python3 build/release.py --base-url https://cdn.example.com/mailu
```

它会：

1. 构建三个 ZIP + 单文件脚本；
2. 把 `deploy/auto/bootstrap.sh` 里的 `__MAILU_BASE_URL__` 替换成你的地址，输出 `dist/install.sh`；
3. 生成 `dist/latest.json`（含 `version`、各包 `url`/`sha256`/`size`）；
4. 生成 `dist/SHA256SUMS`、`dist/RELEASE.md`（含上传命令）。

也可以直接用环境变量：

```bash
MAILU_BASE_URL=https://oss-cn.example.com/mailu python3 build/release.py
```

### 方式二：运行时覆盖（不重新构建）

```bash
MAILU_BASE_URL=https://cdn.example.com/mailu \
  bash <(curl -fsSL https://cdn.example.com/mailu/install.sh) \
  --domain example.com --hostname mail.example.com --yes
```

### 方式三：直接指定包地址（不用 latest.json）

```bash
bash install.sh \
  --pkg-url https://cdn.example.com/mailu/mailu-platform-1.0.0.zip \
  --pkg-sha256 <SHA256> \
  --domain example.com --hostname mail.example.com --yes
```

> 三个方式的优先级：`--pkg-url` > `MAILU_PKG_URL` > `--version` > `latest.json`。

---

## 4. 模式 A：单机一键部署（最常用）

### 4.1 最短命令

```bash
# 交互式：脚本会依次询问域名、主机名
curl -fsSL https://cdn.example.com/mailu/install.sh | sudo bash

# 全自动：域名/主机名/管理员/证书方式一次给全
curl -fsSL https://cdn.example.com/mailu/install.sh | sudo bash -s -- \
  --domain example.com \
  --hostname mail.example.com \
  --tls letsencrypt \
  --yes
```

### 4.2 执行链路（10 步，全自动）

```
[1] 解析参数 → 打印制品地址与安装目录
[2] root 检查（非 root 自动 sudo 重入）
[3] 依赖检查：curl/wget、tar、unzip/python3、sha256 工具
[4] 拉取 latest.json → 解析 version / url / sha256
[5] 下载 zip 到临时目录（3 次重试、支持代理与 --insecure）
[6] SHA256 校验（不匹配立即中止，防止投毒/半包）
[7] 解压到 /opt/mailu-deploy/releases/<version>，切换 current 软链接
[8] 写入 /opt/mailu-deploy/.state（版本/SHA/时间，便于审计）
[9] 调用 deploy/install.sh：识别发行版 → 装 Docker → 下载 Mailu 安装包
    → 生成 compose/mailu.env → TLS → 防火墙 → 起容器 → 建域名/管理员/DKIM
[10] 输出报告：后台地址、初始账号、API 令牌、DNS 记录、客户端参数
```

### 4.3 产物落盘位置

| 路径 | 说明 |
|---|---|
| `/opt/mailu-deploy/releases/<version>/` | 脚本包（可并存多版本，用于回滚） |
| `/opt/mailu-deploy/current` → `releases/<version>` | 当前生效版本 |
| `/opt/mailu-deploy/.state` | 最近一次安装记录（版本/来源/SHA256/时间） |
| `/mailu/` | Mailu 运行目录（compose、mailu.env、data、mail、dkim、certs） |
| `/etc/mailu-deploy/mailu-deploy.conf` | 安装参数（后续升级/备份脚本直接复用） |
| `/var/log/mailu-deploy.log` | 安装日志 |

### 4.4 关键参数

| 参数 | 说明 |
|---|---|
| `--domain` / `--hostname` | 主域名 / 邮件主机名（必填，主机名需已解析到本机） |
| `--tls` | `letsencrypt`(默认) / `certbot` / `cert`(自签或自有证书) / `notls` |
| `--yes` | 非交互 |
| `--dry-run` | 只打印将要执行的步骤（可先给客户演示） |
| `--version 1.0.0` | 指定脚本包版本（跳过 latest.json） |
| `--install-dir` | 脚本包目录（默认 `/opt/mailu-deploy`） |
| `--no-verify` | 跳过 SHA256（**仅调试**，不建议） |
| `--insecure` | 跳过 HTTPS 证书校验（内网自签 CDN 场景） |
| `--upgrade` | 升级脚本包 + 更新 Mailu 镜像（升级前自动备份） |
| `--rollback` | 回滚到上一个脚本包版本 |
| `--list` | 列出本机已安装的脚本包版本 |

其余参数（`--admin-user`、`--api-token`、`--webmail`、`--mirror-prefix`、`--docker-org`…）
原样转发给 `deploy/install.sh`，见 `deploy/README.md`。

---

## 5. 模式 B：全局批量部署（几十台 VPS）

### 5.1 节点清单

`fleet.nodes`（每行：目标 主域名 邮件主机名 [TLS] ["额外参数"]）：

```
# root@host:port   domain          mail hostname        tls        extra
root@203.0.113.10:22   example.com  mail.example.com   letsencrypt
root@203.0.113.11:22   example.net  mail.example.net   letsencrypt
deploy@198.51.100.7:2222  example.org  mail.example.org  cert  "--webmail roundcube"
```

### 5.2 执行

```bash
# 1) 本机准备好到各 VPS 的免密登录
ssh-keygen -t ed25519 -N "" -f ~/.ssh/mailu_ops
for h in 203.0.113.10 203.0.113.11; do ssh-copy-id -i ~/.ssh/mailu_ops.pub root@$h; done

# 2) 先演练（只打印将执行的 ssh 命令，不连接）
bash deploy/auto/fleet-deploy.sh --nodes fleet.nodes --ssh-key ~/.ssh/mailu_ops \
     --base-url https://cdn.example.com/mailu --dry-run

# 3) 正式批量部署（4 并发，按主机名逐台日志）
bash deploy/auto/fleet-deploy.sh --nodes fleet.nodes --ssh-key ~/.ssh/mailu_ops \
     --base-url https://cdn.example.com/mailu --parallel 4 --yes \
     --report fleet-result.csv

# 4) 只补某一台
bash deploy/auto/fleet-deploy.sh --nodes fleet.nodes --only 203.0.113.11 --yes
```

### 5.3 行为说明

* 每台机器：`ssh` 执行 `curl -fsSL <base>/install.sh | sudo bash -s -- <参数>`；
  若制品站点不公开，可加 `--transport scp`，改为先 `scp` 引导脚本再远端执行；
* 并发上限 `--parallel`（默认 4），避免同时拉镜像把出口带宽打满；
* 每台日志：`logs/fleet-<时间>/<host>.log`，汇总：`logs/fleet-<时间>/summary.txt`；
* 结束打印汇总表，任一节点失败 → 退出码 1（可直接接 CI/告警）；
* `--report x.csv` 输出 `host,domain,result,log` 供工单系统导入。

### 5.4 大规模建议

| 规模 | 建议 |
|---|---|
| ≤ 10 台 | `--parallel 4`，本机直接跑 |
| 10–50 台 | `--parallel 6~8`，错峰执行；或按地域拆多个清单文件 |
| > 50 台 | 用 Ansible/Terraform 调同一份 `install.sh`；引导脚本本身就是幂等的 |
| 镜像带宽 | 先在 1 台 `docker pull` 后 `docker save`，内网 `docker load`；或 `--docker-org` 指向内网镜像仓库 |
| 制品带宽 | `latest.json` 短 TTL，zip 长 TTL（文件名带版本号）；同一版本不要覆盖文件名 |---

## 6. 模式 C：中台容器化自动上线（Docker）

中台自身也可以用一条命令上线，首次启动自动初始化，无需打开 `/setup`。

### 6.1 一键启动

```bash
# 1) 服务器上准备目录
mkdir -p /opt/mailu-console && cd /opt/mailu-console

# 2) 从制品站点取 compose 与配置模板（也可直接从 zip 里取）
curl -fsSLO https://cdn.example.com/mailu/mailu-platform-1.0.0.zip
unzip -q mailu-platform-1.0.0.zip
cd mailu-platform-1.0.0/mailu-console/docker

# 3) 配置环境变量
cp .env.example .env
vi .env            # 必改: MC_DOMAIN / MC_ADMIN_PASS
# 4) 上线（Caddy 自动申请 HTTPS 证书）
docker compose -f compose.prod.yaml up -d
```

启动过程：

```
console 容器启动 → entrypoint.sh 发现没有 config/config.php
  → 调用 app/cli/install.php（读 MC_* 环境变量）
      → 写 config/config.php（含随机 app.key）
      → 建表（SQLite 或 MySQL，可 --wait-db 等 DB 就绪）
      → 创建管理员（MC_ADMIN_USER / MC_ADMIN_PASS）
      → 生成中台 API 令牌（打印到容器日志）
  → 启动 Apache
caddy 容器 → 申请证书 → 反向代理到 console:80
```

查看自动生成的管理员与令牌：

```bash
docker compose -f compose.prod.yaml logs console | grep -E "管理员|API 令牌"
```

### 6.2 关键环境变量

| 变量 | 说明 |
|---|---|
| `MC_DOMAIN` | 中台域名（Caddy 用它申请证书） |
| `MC_ADMIN_USER` / `MC_ADMIN_PASS` | 首次启动创建的管理员（密码必填，≥10 位） |
| `MC_APP_NAME` | 站点名称 |
| `MC_DB_DRIVER` | `sqlite`（默认）或 `mysql` |
| `MC_DB_*` | MySQL 主机/库名/账号（`--profile mysql` 可一起启动 MariaDB） |
| `MC_BASE_PATH` | 部署在子目录时填写（如 `console`） |
| `MC_INSTALL_ARGS` | 附加参数，如 `--wait-db=60` |
| `MC_AUTO_INSTALL` | 默认 1；设为 0 则保留 `/setup` 手动向导 |

### 6.3 数据持久化与备份

* 卷：`console-storage`（SQLite + 生成的脚本）、`console-config`（config.php）、`caddy-data`（证书）；
* 备份：`docker run --rm -v console-storage:/data -v $PWD:/backup alpine tar czf /backup/storage.tgz -C /data .`
* 迁移：把三个卷 + `.env` 搬到新机器即可（`app.key` 在 `console-config` 里，必须保留）。

### 6.4 探活与监控

```bash
curl -fsS https://console.example.com/healthz.php   # {"ok":true,"installed":true,"db":"up"}
```

`compose.prod.yaml` 已内置 healthcheck（30s 间隔），可接入 Zabbix/Uptime-Kuma/Prometheus blackbox。

---

## 7. 版本、发布与回滚

### 7.1 一次发布的完整动作

```bash
# 1) 改代码后构建 + 注入地址
python3 build/release.py --base-url https://cdn.example.com/mailu \
        --version 1.0.1 --notes "修复账号同步超时"

# 2) 上传（选一个，确保 latest.json 最后上传，避免客户端拿到不一致的清单）
ossutil cp dist/mailu-platform-1.0.1.zip oss://bucket/mailu/
ossutil cp dist/mailu-console-1.0.1.zip   oss://bucket/mailu/
ossutil cp dist/mailu-deploy-1.0.1.zip    oss://bucket/mailu/
ossutil cp dist/mailu-deploy.bundle.sh    oss://bucket/mailu/
ossutil cp dist/install.sh                oss://bucket/mailu/
ossutil cp dist/latest.json               oss://bucket/mailu/     # 最后传

# 3) 验证
curl -fsSL https://cdn.example.com/mailu/latest.json | head -20
curl -fsSL https://cdn.example.com/mailu/install.sh | bash -s -- --dry-run --domain a.com --hostname mail.a.com
```

### 7.2 latest.json 结构

```json
{
  "name": "mailu-platform",
  "version": "1.0.1",
  "released_at": "2026-10-12T15:30:00+0800",
  "base_url": "https://cdn.example.com/mailu",
  "notes": "修复账号同步超时",
  "files": {
    "platform": { "url": ".../mailu-platform-1.0.1.zip", "sha256": "…", "size": 208000 },
    "console":  { "url": ".../mailu-console-1.0.1.zip",  "sha256": "…", "size": 150000 },
    "deploy":   { "url": ".../mailu-deploy-1.0.1.zip",   "sha256": "…", "size": 58000  },
    "bundle":   { "url": ".../mailu-deploy.bundle.sh",   "sha256": "…", "size": 70000  },
    "bootstrap":{ "url": ".../install.sh",               "sha256": "…", "size": 12000  }
  }
}
```

### 7.3 升级与回滚

```bash
# 升级（自动先备份 Mailu 数据，再拉新包、切 current、更新镜像）
bash /opt/mailu-deploy/current/deploy/auto/bootstrap.sh --upgrade

# 只看有哪些版本
bash /opt/mailu-deploy/current/deploy/auto/bootstrap.sh --list

# 脚本包回滚
bash /opt/mailu-deploy/current/deploy/auto/bootstrap.sh --rollback

# Mailu 镜像回滚（脚本包回滚不会自动回滚镜像）
bash /opt/mailu-deploy/current/deploy/install.sh --action update --new-version 2024.06.61 --yes
```

**发布纪律**

1. 文件名带版本号，永不覆盖（CDN 缓存不可控）；
2. `latest.json` 最后上传（先有包再有清单）；
3. 每次发布保留上一版本至少 7 天，便于回滚；
4. 生产环境建议 `--pkg-sha256` 固定哈希，或在内网镜像/制品库做二次校验。

---

## 8. CI/CD：自动构建并发布到制品站点

### 8.1 GitHub Actions 示例

```yaml
name: release
on:
  push:
    tags: ['v*']
jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-python@v5
        with: { python-version: '3.11' }
      - name: Build
        run: python3 build/release.py --base-url https://cdn.example.com/mailu --version "${GITHUB_REF_NAME#v}"
      - name: Publish (对象存储)
        env:
          OSS_ACCESS_KEY_ID: ${{ secrets.OSS_AK }}
          OSS_ACCESS_KEY_SECRET: ${{ secrets.OSS_SK }}
        run: |
          pip install oss2 ossutil 2>/dev/null || true
          ossutil cp -f dist/ oss://your-bucket/mailu/ -r
      - name: Publish (GitHub Release 备选)
        uses: softprops/action-gh-release@v2
        with:
          files: |
            dist/mailu-platform-*.zip
            dist/mailu-console-*.zip
            dist/mailu-deploy-*.zip
            dist/mailu-deploy.bundle.sh
            dist/install.sh
            dist/latest.json
```

### 8.2 自建 GitLab CI

```yaml
release:
  image: python:3.11
  script:
    - python3 build/release.py --base-url "$MAILU_BASE_URL" --version "${CI_COMMIT_TAG#v}"
    - rclone copy dist/ remote:bucket/mailu/ --include "*"
  only: [tags]
```

### 8.3 校验门禁（建议加在 CI 里）

```bash
# 语法检查
for f in deploy/*.sh deploy/lib/*.sh deploy/bin/* deploy/auto/*.sh; do bash -n "$f" || exit 1; done
# shellcheck（可选）
shellcheck -S error deploy/*.sh deploy/lib/*.sh deploy/auto/*.sh
# PHP 语法
find mailu-console -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
# 部署脚本自检（生成配置到临时目录，29 项断言）
TEST_ROOT=/tmp/selfcheck bash deploy/tests/selfcheck.sh
```

---

## 9. 安全设计

| 风险 | 对策 |
|---|---|
| 制品被篡改 | 引导脚本强制 SHA256 校验；`latest.json` 与包同源，可再用 `--pkg-sha256` 固定 |
| 中间人 | 默认 `curl --proto '=https'`；`--insecure` 需显式开启 |
| 误装到错误机器 | `--dry-run` 先演练；安装前打印域名/主机名/目录并要求确认（`--yes` 可跳过） |
| 密钥泄漏 | 中台 API 令牌只打印一次在容器日志，可随时在设置页重置；`/opt/mailu-deploy/.state` 权限 600 |
| 权限过大 | 脚本要求 root（装 Docker/改防火墙），但所有操作幂等、可 `--no-docker`/`--no-firewall` 拆分执行 |
| 供应链 | 只从你自己的制品站点取包；Mailu 官方安装包仍然从 GitHub/镜像拉取（可用 `--mirror-prefix`） |
| 内网合规 | `--transport scp` 可让制品站点不对外暴露；离线部署见 `deploy/README.md` 第 6 节 |

---

## 10. 上线检查清单（Runbook）

**发布前**

- [ ] `python3 build/release.py --base-url <你的地址>` 无报错
- [ ] `dist/latest.json` 中 `version`/`url`/`sha256` 正确
- [ ] 上传顺序：zip → bundle → install.sh → latest.json（清单最后）
- [ ] `curl -fsSL <base>/install.sh | bash -s -- --dry-run --domain test.local --hostname mail.test.local` 通过
- [ ] 目标域名 A 记录已指向 VPS；云安全组已放行 25/80/443/465/587/993/995

**部署后**

- [ ] `curl -I https://mail.<域名>/admin` 返回 200/302
- [ ] `/opt/mailu-deploy/.state` 版本与预期一致
- [ ] `mailu-ctl status` 容器全部 Up
- [ ] 中台添加服务器 → 检查连接成功 → 同步出域名
- [ ] `mailu-ctl dkim <域名>` 已生成，DNS 四条记录已加
- [ ] 用一个测试账号跑"收发登录测试"（IMAP + SMTP 均成功）

**异常处置**

| 现象 | 处理 |
|---|---|
| `SHA256 校验失败` | 重新下载；核对 `latest.json` 与 CDN 缓存；不要跳过校验上线 |
| `无法获取发布清单` | 检查 `base_url`、HTTPS 证书、对象存储权限（`latest.json` 需公共读） |
| `安装包中找不到 deploy/install.sh` | 上传的 zip 不对（应为 platform 或 deploy 包） |
| 多台机器同时失败 | 多为制品站点/镜像仓库被限流；降低 `--parallel`，或先内网缓存镜像 |
| 升级后异常 | `bootstrap.sh --rollback` + `install.sh --action update --new-version <旧版>` |
---

## 11. 补充：只改一个地址的三种最快方式

### 11.1 直接改发布好的 install.sh（不重新构建）

```bash
# 把 dist/install.sh 里唯一的地址换掉（第 1 处的 MAILU_BASE_URL 默认值）
sed -i 's#https://cdn.example.com/mailu#https://你的地址/mailu#' dist/install.sh
grep -n 'MAILU_BASE_URL:-\|MAILU_BASE_URL:-' dist/install.sh | head -3   # 确认
```

### 11.2 运行时指定（连 install.sh 都不用改）

```bash
curl -fsSL https://你的地址/mailu/install.sh | sudo bash -s -- \
  --base-url https://你的地址/mailu \
  --domain example.com --hostname mail.example.com --yes
```

### 11.3 重新构建（推荐，会同时刷新 latest.json 与各包 sha256）

```bash
python3 build/release.py --base-url https://你的地址/mailu --version 1.0.1
```

## 12. 两个实用开关

### 12.1 `--extract-only`：只预置脚本包，不安装

适合灰度/预拉取：先把包下发到几十台机器并校验完成，稍后在业务低峰再执行安装。

```bash
curl -fsSL https://cdn.example.com/mailu/install.sh | sudo bash -s -- --extract-only
# 之后随时安装（参数已记住版本目录）
sudo bash /opt/mailu-deploy/current/deploy/install.sh --domain a.com --hostname mail.a.com --yes
```

### 12.2 `--notes-file`：发布说明含中文时使用

Windows 控制台/部分 CI 用命令行传中文参数可能乱码，用文件传入更稳：

```bash
printf '修复账号同步超时；新增批量导入\n' > notes.txt
python3 build/release.py --base-url https://cdn.example.com/mailu --version 1.0.1 --notes-file notes.txt
```

## 13. 一句话记忆

> **改一个 `base_url` → 上传 dist/ → VPS 上 `curl ... | sudo bash -s -- --domain X --hostname mail.X --yes`。**
> 多机就换成 `fleet-deploy.sh --nodes fleet.nodes`；中台自己就用 `docker compose -f compose.prod.yaml up -d`。