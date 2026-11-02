# 更新日志

本项目遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

## [1.0.0] - 2026-11-02

首个开源版本。

### 新增

- **PHP 中台**（`mailu-console/`，纯原生 PHP 7.4+，无需 Composer）
  - 多服务器 / 多域名账号管理，走 Mailu REST API（`/api/v1`）
  - 域名、账号、别名增删改查；配额、转发、启停、批量导入（粘贴 / CSV）
  - 收发邮件登录信息卡片：IMAP/POP3/SMTP/Webmail，可导出 CSV / TXT / HTML / autoconfig XML
  - 收发登录测试：真实握手 IMAP / POP3 / SMTP（不发信）
  - DNS 记录生成（MX / SPF / DKIM / DMARC）与在线校验
  - 部署中心：按参数生成 Linux 安装脚本，可下载或经 SSH 远程执行
  - 操作审计、系统设置、对外 JSON API（Bearer 令牌）
  - 支持两种部署形态：`public/` 为站点根，或扁平版（子目录直接上传）
- **Linux 部署脚本**（`deploy/`）
  - 一条命令安装 Mailu：识别发行版 → 装 Docker → 下载官方安装包 → 生成配置 → TLS → 防火墙 → 启动 → 初始化 → 输出报告
  - 兼容 Debian/Ubuntu、RHEL/CentOS/Rocky/Alma/Oracle/Fedora/Amazon Linux、openSUSE、Alpine、Arch
  - 幂等；`--dry-run` / `--status` / `--backup` / `--update` / `--uninstall`
  - `mailu-ctl`：状态、日志、增删域名/账号/别名、生成 DKIM、一键体检 `doctor`
- **全局自动部署**
  - `deploy/auto/bootstrap.sh`：从任意 HTTPS 制品站点下载安装包，SHA256 校验后按版本目录解压
  - `deploy/auto/fleet-deploy.sh`：读节点清单，SSH 并发批量部署
  - `build/release.py`：注入制品地址，生成 `install.sh` 与 `latest.json`

### 修复（来自真实部署踩坑）

- 默认生成 unbound 解析器并为服务注入 `dns:`；解析器不可用时自动回退到公共 DNSSEC 校验型 DNS
- `AUTH_RATELIMIT_IP` / `AUTH_RATELIMIT_USER` 现在生成 `50/hour`、`100/day`（裸数字会让限流接口全部 500）
- 管理员创建改为 `INITIAL_ADMIN_*` + `flask mailu admin <前缀> <域名> <密码>`，并在 API 侧复核提升 `global_admin`
- 安装收尾硬校验：admin 8080 响应、API 返回 JSON 数组、`/admin` 与 `/webmail` 状态码
- 详见 `docs/TROUBLESHOOTING.md` 的五个真实案例