# 邮件服务器（Linux VPS）部署说明

本文件是 `deploy/` 脚本包的使用说明摘要；完整内容（支持的系统矩阵、全部参数、
离线部署、备份/升级/卸载、故障排查）请阅读 **[deploy/README.md](../deploy/README.md)**。

## 推荐流程

1. 在中台“部署中心”填写参数并生成脚本：

   * 主域名 `example.com`、邮件主机名 `mail.example.com`（必须已解析到 VPS 公网 IP）
   * TLS：`letsencrypt`（默认，需 80 端口公网可达）
   * 管理员账号/密码、API 令牌（脚本自动生成，务必保存）
   * 可选：网页邮箱类型、安装目录、容器网段、下载加速前缀

2. 上传并在 VPS 上执行：

   ```bash
   scp mailu-install-example.com-*.sh root@VPS:/root/
   ssh root@VPS 'bash /root/mailu-install-example.com-*.sh --yes'
   ```

3. 按脚本输出添加 DNS 记录（MX / SPF / DKIM / DMARC / A）与云安全组端口。

4. 回到中台添加服务器（地址 + API 令牌）→ 检查连接 → 同步。

## 直接使用脚本包（不经中台）

```bash
unzip mailu-deploy-1.0.0.zip && cd mailu-deploy
sudo bash install.sh --domain example.com --hostname mail.example.com --yes
sudo bash install.sh --status
sudo bash bin/mailu-ctl add-domain example.net
sudo bash bin/mailu-ctl add-user zhangsan example.com 'Passw0rd!23' 2048
sudo bash bin/mailu-ctl dkim example.com
```

## 必须放行的端口

| 端口 | 用途 |
|---|---|
| 25 | 服务器之间收信（多数云厂商默认封禁，需工单解封） |
| 80 | Let's Encrypt 证书校验 / HTTP 跳转 |
| 443 | 网页邮箱、管理后台、API（同时用于 IMAP/SMTP 的隐式 TLS） |
| 110 / 995 | POP3 / POP3S |
| 143 / 993 | IMAP / IMAPS |
| 465 / 587 | SMTP 隐式 TLS / STARTTLS 提交 |
| 4190 | Sieve 过滤器（可选） |

## DNS 记录

| 类型 | 主机记录 | 值 |
|---|---|---|
| A | `mail.example.com` | VPS 公网 IP |
| MX | `example.com` | `mail.example.com`（优先级 10） |
| TXT | `example.com` | `v=spf1 mx ~all` |
| TXT | `dkim._domainkey.example.com` | DKIM 公钥（`mailu-ctl dkim example.com` 输出） |
| TXT | `_dmarc.example.com` | `v=DMARC1; p=quarantine; rua=mailto:postmaster@example.com` |
| PTR | VPS IP 反向解析 | `mail.example.com`（在云服务商处设置，影响发信信誉） |

## 版本与镜像

* 默认安装版本由中台“系统设置 / 部署中心”决定（默认 `2024.06.61`），脚本中的 `--version` 可覆盖；
* 镜像默认来自 `ghcr.io/mailu`，国内 VPS 可用 `--docker-org` 换成镜像加速仓库；
* 安装包（GitHub tarball）可用 `--mirror-prefix https://ghproxy.net/` 加速，
  或用 `packages/fetch-package.sh` 预先下载后随目录一起拷贝到目标机实现离线安装。