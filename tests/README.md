# 测试

| 文件 | 作用 |
|---|---|
| `mock_mailu.py` | 模拟 Mailu REST API（`/api/v1/domain`、`/user`、`/alias`、DKIM），提供 `POST /__reset` 重置状态 |
| `smoke_test.py` | 中台端到端冒烟：安装 → 登录 → 添加服务器 → 同步 → 建账号 → 登录信息 → 部署中心 → 健康检查 |
| `run_smoke.sh` | 一键启动（PHP 内置服务器 + mock）并执行冒烟，结束后自动清理 |

```bash
bash tests/run_smoke.sh                                  # 需要 php 与 python3
PHP_PORT=8199 MOCK_PORT=9199 bash tests/run_smoke.sh     # 换端口
```

验证内容：安装向导写配置/建库/建管理员、会话与 CSRF、经 REST API 添加服务器并首次同步、
建账号后密码加密保存且登录信息页可读、部署中心可用、`/healthz.php` 正常、无令牌访问 `/api/v1/*` 被拒。

## 部署脚本自检（不需要 Docker / root）

```bash
bash deploy/tests/selfcheck.sh
```

生成 `docker-compose.yml` 与 `mailu.env` 到临时目录，逐项断言镜像命名、端口、DNSSEC resolver、
限流单位、初始管理员变量、模板渲染、自签证书等。

## 真实环境验证

```bash
# 收发闭环（需要一台可用的 Mailu 服务器）
python3 - <<'PY'
import smtplib, imaplib, ssl
from email.message import EmailMessage
H, U, P = 'mail.example.com', 'user@example.com', 'password'
ctx = ssl.create_default_context(); ctx.check_hostname = False; ctx.verify_mode = ssl.CERT_NONE
m = EmailMessage(); m['From'] = U; m['To'] = U; m['Subject'] = 'self test'; m.set_content('ok')
s = smtplib.SMTP_SSL(H, 465, context=ctx, timeout=20); s.login(U, P); s.send_message(m); s.quit()
print('SMTP OK')
i = imaplib.IMAP4_SSL(H, 993, ssl_context=ctx); i.login(U, P); i.select('INBOX'); print('IMAP OK'); i.logout()
PY

# 服务器一键体检
sudo bash /opt/mailu-deploy/current/deploy/bin/mailu-ctl doctor
```