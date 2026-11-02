# 接口说明

中台提供两层接口：

1. **对外管理 API（本中台提供）**：`/api/v1/*`，Bearer 令牌鉴权，供开通/计费/工单系统调用。
2. **Mailu API（被中台调用）**：`https://mail.example.com/api/v1/*`，中台内部的 `MailuClient` 封装。

---

## 一、中台管理 API

* 基础地址：`https://<中台地址>/api/v1`
* 鉴权：`Authorization: Bearer <中台 API 令牌>`（在“系统设置”页查看/重置）；
  已登录的浏览器会话同样可以调用。
* 返回：JSON，成功为 `{"ok":true,...}`，失败为 `{"ok":false,"error":"..."}`（HTTP 400/401）。
* 删除类接口请使用 POST（避免被中间代理拦截）。

| 方法 | 路径 | 说明 | 主要参数 |
|---|---|---|---|
| GET | `/ping` | 健康检查与统计 | — |
| GET | `/stats` | 服务器/域名/账号/别名统计 | — |
| GET | `/servers` | 服务器列表（含健康状态） | — |
| GET | `/domains` | 域名列表 | `server_id`、`q` |
| GET | `/users` | 账号列表（分页） | `server_id`、`domain`、`q`、`status`、`page`、`per_page` |
| GET | `/aliases` | 别名列表 | `server_id`、`domain`、`q`、`page` |
| GET | `/credentials` | 单个账号的登录信息（含明文密码，需已保存） | `id` 或 `email`(+`server_id`) |
| POST | `/users/create` | 创建账号 | `server_id`、`email` 或 `local_part`+`domain_name`、`password`(可选)、`quota_mb`、`displayed_name`、`comment`、`global_admin`、`enabled` |
| POST | `/users/update` | 修改账号 | `id` 或 `email`(+`server_id`)，可选 `displayed_name`、`comment`、`quota_mb`、`spam_threshold`、`enabled`、`global_admin`、`enable_imap`、`enable_pop`、`forward_enabled`、`forward_destination`、`spam_enabled` |
| POST | `/users/password` | 重置密码 | `id` 或 `email`(+`server_id`)、`password`(可选，留空随机) |
| POST | `/users/delete` | 删除账号 | `id` 或 `email`(+`server_id`) |
| POST | `/domains/create` | 创建域名 | `server_id`、`name`、`comment`、`max_users`、`max_aliases`、`max_quota_mb`、`alternatives` |
| POST | `/domains/delete` | 删除域名 | `domain_id`、`delete_users`(可选) |
| POST | `/sync` | 触发同步 | `server_id`(可选，缺省同步全部)、`scope`=all/domains/users/aliases |

### 示例

```bash
TOKEN='中台设置页里的令牌'
BASE='https://console.example.com/api/v1'

# 创建账号（返回初始密码）
curl -sS -X POST "$BASE/users/create" \
  -H "Authorization: Bearer $TOKEN" \
  -d "server_id=1" -d "email=zhangsan@example.com" -d "quota_mb=2048" \
  -d "displayed_name=张三"

# 重置密码
curl -sS -X POST "$BASE/users/password" \
  -H "Authorization: Bearer $TOKEN" \
  -d "email=zhangsan@example.com" -d "server_id=1"

# 取登录信息（IMAP/SMTP 参数 + 密码）
curl -sS "$BASE/credentials?email=zhangsan@example.com&server_id=1" \
  -H "Authorization: Bearer $TOKEN"

# 批量同步
curl -sS -X POST "$BASE/sync" -H "Authorization: Bearer $TOKEN" -d "scope=all"
```

---

## 二、Mailu API 对应关系（中台内部实现）

中台使用 Mailu 2.x 的 REST API（`WEB_API=/api`，即 `/api/v1`），认证方式为
`Authorization: Bearer <API_TOKEN>`（`mailu.env` 中的 `API_TOKEN`），
也兼容 HTTP Basic（管理员账号）方式。

| 中台动作 | Mailu 接口 |
|---|---|
| 连接检查 / 列出域名 | `GET /api/v1/domain` |
| 创建域名 | `POST /api/v1/domain`（`name`、`comment`、`max_users`、`max_aliases`、`max_quota_bytes`、`signup_enabled`、`alternatives`） |
| 域名详情（含 DNS 记录） | `GET /api/v1/domain/{name}` → 返回 `dns_mx`、`dns_spf`、`dns_dkim`、`dns_dmarc`、`dns_autoconfig` |
| 修改 / 删除域名 | `PATCH`（旧版本回退 `PUT`）/ `DELETE /api/v1/domain/{name}` |
| 生成 DKIM + DMARC 密钥 | `POST /api/v1/domain/{name}/dkim` |
| 域名下的账号 | `GET /api/v1/domain/{name}/users` |
| 列出账号 | `GET /api/v1/user` |
| 创建账号 | `POST /api/v1/user`（`email`、`raw_password`、`quota_bytes`、`global_admin`、`enabled`、`displayed_name`、`forward_destination[]`…） |
| 修改账号 / 重置密码 | `PATCH /api/v1/user/{email}`（`raw_password` 即改密） |
| 删除账号 | `DELETE /api/v1/user/{email}` |
| 列表 / 创建 / 修改 / 删除别名 | `GET|POST /api/v1/alias`、`GET|PATCH|DELETE /api/v1/alias/{email}`（`destination` 为数组） |
| 中继（relay） | `GET|POST /api/v1/relay`、`DELETE /api/v1/relay/{name}` |
| 为账号签发 API 令牌 | `GET|POST /api/v1/token` |
| 接口文档（Swagger） | `GET /api/v1/swagger.json`，或浏览器访问 `https://mail.example.com/api/` |

兼容性处理（`app/Service/MailuClient.php`）：

* 列表接口在不同版本可能返回字符串数组、对象数组或 `{items:[...]}`，客户端统一归一化；
* 更新操作先 `PATCH`，遇到 404/405 自动回退 `PUT`；
* 邮箱地址做 URL 编码，避免 `@` 与特殊字符导致 404；
* 连接失败自动重试一次，401/403 给出“令牌或 API 开关”的中文提示。

### 用 curl 直接验证 Mailu API

```bash
curl -sS -k -H "Authorization: Bearer $MAILU_API_TOKEN" https://mail.example.com/api/v1/domain
curl -sS -k -X POST -H "Authorization: Bearer $MAILU_API_TOKEN" -H 'Content-Type: application/json' \
     -d '{"email":"test@example.com","raw_password":"Passw0rd!23","quota_bytes":2147483648}' \
     https://mail.example.com/api/v1/user
```

---

## 三、错误码

| HTTP | 含义 | 处理建议 |
|---|---|---|
| 400 | 参数不合法或 Mailu 返回业务错误 | 查看 `error` 字段（中台会把 Mailu 的 message 透传） |
| 401 | 中台令牌缺失/无效，或 Mailu 令牌无效 | 检查 `Authorization` 头 / 服务器配置里的 API 令牌 |
| 404 | 对象不存在（账号、域名、脚本） | 先同步再操作 |
| 409 | Mailu 侧重复（账号/域名已存在） | 视为幂等成功或改用 update |
| 419 | CSRF 校验失败（仅表单） | 这是浏览器表单接口，API 调用不会遇到 |
| 500 | 中台内部错误 | 查看 `storage/logs/app-YYYY-MM-DD.log` |