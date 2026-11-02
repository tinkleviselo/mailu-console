# 安全策略

## 支持范围

| 版本 | 是否接受安全修复 |
|---|---|
| 1.x（当前） | 是 |

## 报告漏洞

**请不要在公开 Issue 中披露漏洞细节。** 请通过以下任一方式私下联系维护者：

- GitHub Security Advisories（仓库 **Security → Report a vulnerability**，推荐）
- 仓库 About 中公开的邮箱

请在报告中包含：影响版本、复现步骤、影响面（是否需要管理员权限、是否可远程触发）、以及你能提供的修复建议。

我们会在 7 天内确认收到，并在修复发布后致谢（如你愿意署名）。

## 这个项目处理哪些敏感数据

中台会保存：

| 数据 | 存储方式 |
|---|---|
| 中台管理员密码 | `password_hash()`（bcrypt） |
| Mailu API 令牌 | AES-256-GCM 加密（密钥来自 `config.php` 的 `app.key`） |
| 邮箱账号密码（仅通过中台创建/重置的） | 同上，AES-256-GCM |
| 对外 API 令牌 | 只存 SHA-256 摘要 |

## 部署注意事项

- **必须使用 HTTPS** 对外提供中台；建议再加 IP 白名单或 Basic 认证
- `config/config.php` 与 `storage/` 必须仅 Web 服务器用户可读；`app.key` 丢失后已加密的数据无法恢复
- 不要把 `config.php`、`storage/*.sqlite`、`logs/` 提交到仓库或放进公开备份
- 部署脚本需要 root（安装 Docker、改防火墙），请先在测试机 `--dry-run` 演练
- Mailu 侧的 `API_TOKEN` 等同于管理员权限，泄露后应立即在 `mailu.env` 轮换并重启 `admin`