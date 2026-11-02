# 故障排查手册（真实案例版）

本手册的每一条都来自实际部署中踩过的坑，按"症状 → 一句话根因 → 一条命令修复"组织。
遇到问题先跑一键体检，再按症状查表。

```bash
sudo bash /opt/mailu-deploy/current/deploy/bin/mailu-ctl doctor
```

体检项：容器状态 / admin 8080 / 限流配置格式 / REST API / `/admin` `/webmail` / DNS 解析 /
DNSSEC 校验能力 / DKIM 密钥 / 证书剩余天数。全部 OK 则没有阻断性问题。

---

## 案例 1：`/webmail` 返回 500，`/admin` 也打不开，安装时卡在"等待 Mailu API 就绪"

**症状链**

```
front: connect() failed (111: Connection refused) ... subrequest "/internal/auth/user", upstream "http://192.168.203.4:8080/..."
front: auth request unexpected status: 502
admin: CRITICAL:root:Your DNS resolver at 127.0.0.11 isn't doing DNSSEC validation
```

**根因**：Mailu 的 admin 容器启动时强制要求 DNS 返回 DNSSEC 的 `AD` 位（`core/admin/start.py` 的 `test_DNS()`），
而 Docker 内置 DNS `127.0.0.11` 不做 DNSSEC 校验。admin 卡在 `while True: ... sleep(5)` 里永不启动
→ 8080 没人监听 → front 的 auth 子请求失败 → `/webmail` 500、`/admin` 502、API 不可用。

**修复**（推荐，官方方案）：启用 Mailu 自带的 unbound 解析器

```bash
cd /mailu
cat > docker-compose.override.yml <<'YAML'
services:
  resolver:
    image: ghcr.io/mailu/unbound:2024.06.61
    restart: always
    env_file: mailu.env
    networks:
      default:
        ipv4_address: 192.168.203.254
  admin:
    dns: [192.168.203.254]
  antispam:
    dns: [192.168.203.254]
YAML
docker compose up -d resolver admin antispam && docker compose restart front
```

若 unbound 自身起不来（`dig @192.168.203.254 example.org +adflag` 报 SERVFAIL），退回公共校验型 DNS：

```bash
cat > docker-compose.override.yml <<'YAML'
services:
  admin:
    dns: [9.9.9.9, 1.1.1.1]
  antispam:
    dns: [9.9.9.9, 1.1.1.1]
YAML
docker compose up -d --force-recreate admin antispam && docker compose restart front
```

> 新版部署脚本已默认生成 unbound，并且检测到 DNSSEC 报错时会**自动**切到 9.9.9.9/1.1.1.1 重试。

---

## 案例 2：API 全部 500、后台登录 500、IMAP/SMTP 报 "Temporary authentication failure"

**症状**

```
GET /api/v1/domain  -> 500   {"message": "Internal Server Error"}
POST /admin/        -> 500   Internal Server Error
IMAP login          -> [UNAVAILABLE] Temporary authentication failure
SMTP auth           -> 454 4.7.0 Temporary authentication failure
但 GET /internal/auth/user -> 403（正常）
```

**根因**：`AUTH_RATELIMIT_IP` / `AUTH_RATELIMIT_USER` 缺少单位（写成了裸数字 `50`）。
`limits.parse("50")` 抛 `ValueError`，于是**所有挂了限流装饰器的入口**（API、后台登录、Dovecot 密码校验）全部 500；
而不走限流的 `/internal/auth/user` 正常——这就是为什么症状看起来"互相矛盾"。

**修复**

```bash
cd /mailu
sed -i -E 's/^AUTH_RATELIMIT_IP=([0-9]+)$/AUTH_RATELIMIT_IP=\1\/hour/' mailu.env
sed -i -E 's/^AUTH_RATELIMIT_USER=([0-9]+)$/AUTH_RATELIMIT_USER=\1\/day/' mailu.env
grep -E '^AUTH_RATELIMIT' mailu.env      # 必须是 50/hour、100/day
docker compose up -d admin imap smtp antispam front
```

正确格式：`数字/秒|分钟|小时|天`，例如 `50/hour`、`100/day`；要**关闭**限流就整行删掉（不要写 `0`）。

---

## 案例 3：能登录 `/admin` 但一进去就 500；`config-export` 显示 `global_admin: false`

**根因**：Mailu 后台要求账号是**全局管理员**。而 `flask mailu admin --mode update` 对**已存在的账号只改密码、不提权**
（源码：`manage.py` 中 `global_admin=True` 只在"新建用户"分支设置）。历史遗留的普通账号因此进不了后台。

**修复（三选一）**

```bash
cd /mailu
TOKEN=$(grep '^API_TOKEN=' mailu.env | cut -d= -f2)

# A) API 提权（推荐，不删数据；注意 @ 要写成 %40）
curl -sk -X PATCH -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"global_admin":true}' 'https://mail.<域名>/api/v1/user/admin%40<域名>'

# B) 直接改库（API 也不可用时）
docker compose exec -T admin python3 - <<'PY'
import sqlite3
db = sqlite3.connect('/data/main.db', timeout=10)
db.execute('UPDATE "user" SET global_admin = 1 WHERE email = ?', ('admin@<域名>',))
db.commit()
print(list(db.execute('SELECT email, global_admin FROM "user"')))
db.close()
PY
docker compose restart admin

# C) 删掉重建（无邮件数据时最简单；注意参数顺序 前缀 域名 密码）
docker compose exec admin flask mailu user-delete admin@<域名> -r
docker compose exec admin flask mailu admin admin <域名> '新密码'
```

> 新版脚本通过 `mailu.env` 的 `INITIAL_ADMIN_ACCOUNT/DOMAIN/PW` 让容器首次启动就创建**全局管理员**，
> 安装收尾还会用 API 复核并自动提升。

---

## 案例 4：数据库半成品 —— 迁移没跑完，页面/接口零散报错

**识别**：`docker compose logs admin` 里看不到完整的 `alembic ... Running upgrade` 序列，
或者 gunicorn 起来了但各种查询报 `no such table` / 字段异常。常见于首次安装时被中断（Ctrl+C、超时、反复重启）。

**修复**：重建 admin 数据目录（只影响管理库，邮件正文在 `mail/` 下不受影响）

```bash
cd /mailu
docker compose stop admin
mv data "data.broken.$(date +%s)" && mkdir -p data
docker compose up -d admin
docker compose logs -f --tail=40 admin     # 应看到 alembic 跑完 + "Listening at: http://0.0.0.0:8080"
# 然后重建域名与账号
docker compose exec admin flask mailu domain <域名>
docker compose exec admin flask mailu admin admin <域名> '密码'
```

---

## 案例 5：命令"看起来执行了但没生效" —— bash 的 `!`

```bash
$ curl ... --data-urlencode "password=AdminPass!23456" ...
-bash: !23456: event not found      # 整条命令没执行
```

**根因**：交互式 bash 的历史扩展（history expansion）把 `!` 当成事件引用；双引号内也会触发。

**规避**：密码不含 `!`，或执行 `set +H` 关闭历史扩展，或用单引号（单引号内不展开）。

---

## 通用排查顺序（照着走）

```bash
cd /mailu

# 1) 谁不健康
docker compose ps
docker compose logs --tail=60 admin

# 2) admin 是否真在监听
docker compose exec -T admin sh -c 'curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8080/internal/auth/user'
#    000 = 没监听（看日志找原因）；403/401 = 正常（未认证）

# 3) 关键配置是否合法
grep -E '^(AUTH_RATELIMIT|TLS_FLAVOR|DOMAIN|HOSTNAMES|WEBMAIL|API|API_TOKEN|INITIAL_ADMIN)' mailu.env

# 4) 前端到 admin 的链路
docker compose logs --tail=30 front | grep -iE 'error|auth|upstream'

# 5) DNSSEC 能力
docker compose exec -T admin python3 -c "
import dns.resolver, dns.flags
for ns in dns.resolver.Resolver().nameservers:
    r=dns.resolver.Resolver(configure=False); r.nameservers=[ns]; r.use_edns(0,dns.flags.DO,1232)
    try:
        q=r.resolve('example.org','A',lifetime=4); print(ns, 'AD=', bool(q.response.flags & dns.flags.AD))
    except Exception as e: print(ns,'FAIL',type(e).__name__)
"

# 6) 真实收发（最权威）
python3 - <<'PY'
import smtplib, imaplib, ssl
H,U,P='mail.<域名>','user@<域名>','密码'
ctx=ssl.create_default_context(); ctx.check_hostname=False; ctx.verify_mode=ssl.CERT_NONE
try:
    s=smtplib.SMTP_SSL(H,465,context=ctx,timeout=15); s.login(U,P); print('SMTP OK'); s.quit()
except Exception as e: print('SMTP FAIL',e)
try:
    m=imaplib.IMAP4_SSL(H,993,ssl_context=ctx); m.login(U,P); print('IMAP OK'); m.logout()
except Exception as e: print('IMAP FAIL',e)
PY
```

## 症状速查表

| 症状 | 最可能原因 | 对应案例 |
|---|---|---|
| `/webmail` 500、`/admin` 502 | admin 未启动（DNSSEC / DB / 8080） | 1、4 |
| API 500 + 登录 500 + 收认证明细临时失败 | 限流配置缺单位 | 2 |
| 后台能登录但进去 500 | 账号非全局管理员 | 3 |
| 各种 500 且日志无 traceback、迁移不全 | 数据库半成品 | 4 |
| 命令无输出/无效果 | bash `!` 历史扩展 | 5 |
| 收不到外网邮件 | MX 未生效 / 25 端口被封 / rDNS | 见 DEPLOY.md |
| 发信进垃圾箱 | SPF/DKIM/DMARC 缺失 | 见 DEPLOY.md |