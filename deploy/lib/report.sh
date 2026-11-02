#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# 安装结果汇总输出（DNS 记录 / 客户端设置 / 后续步骤）
# ---------------------------------------------------------------------------

mc_print_banner() {
    mc_hr
    printf '%s  Mailu 邮件系统一键部署脚本 %s\n' "$MC_C_BOLD" "${MAILU_VERSION}"
    printf '  主域名: %s   主机名: %s\n' "${MAILU_DOMAIN:-<未设置>}" "${MAILU_HOSTNAMES:-<未设置>}"
    printf '  安装目录: %s    TLS: %s%s\n' "${MAILU_ROOT}" "${MAILU_TLS}" "$MC_C_RESET"
    mc_hr
}

mc_print_summary() {
    local host
    host="$(mc_primary_hostname)"
    local schema="https"
    if [ "${MAILU_TLS_FLAVOR:-}" = "notls" ]; then
        schema="http"
    fi
    mc_hr
    printf '%s安装完成，以下是本次部署的关键信息%s\n' "$MC_C_BOLD" "$MC_C_RESET"
    mc_hr
    printf '安装目录      : %s\n' "$MAILU_ROOT"
    printf '主域名        : %s\n' "$MAILU_DOMAIN"
    printf '邮件主机名    : %s\n' "$MAILU_HOSTNAMES"
    printf 'Mailu 版本    : %s\n' "$MAILU_VERSION"
    printf 'TLS 模式      : %s\n' "${MAILU_TLS_FLAVOR:-$MAILU_TLS}"
    printf '\n'
    printf '管理后台      : %s://%s/admin\n' "$schema" "$host"
    if [ "${MAILU_WEBMAIL:-none}" != "none" ]; then
        printf '网页邮箱      : %s://%s/webmail\n' "$schema" "$host"
    fi
    printf 'REST API      : %s://%s/api/v1\n' "$schema" "$host"
    printf '管理员账号    : %s@%s\n' "$MAILU_ADMIN_USER" "$MAILU_DOMAIN"
    printf '管理员密码    : %s\n' "$MAILU_ADMIN_PASS"
    printf 'API 令牌      : %s\n' "$MAILU_API_TOKEN"
    printf '\n'
    printf '%s邮件客户端设置（给所有用户）%s\n' "$MC_C_BOLD" "$MC_C_RESET"
    printf '  IMAP 收信   : %s  端口 993 (SSL)\n' "$host"
    printf '  POP3 收信   : %s  端口 995 (SSL)\n' "$host"
    printf '  SMTP 发信   : %s  端口 465 (SSL) 或 587 (STARTTLS)\n' "$host"
    printf '  Sieve 过滤  : %s  端口 4190 (STARTTLS)\n' "$host"
    printf '  账号填写    : 完整邮箱地址，密码即邮箱密码\n'
    printf '\n'
    printf '%s必须配置的 DNS 记录%s（否则收不到信 / 发信进垃圾箱）\n' "$MC_C_BOLD" "$MC_C_RESET"
    printf '  1) MX     %s.  ->  %s  优先级 10\n' "$MAILU_DOMAIN" "${MC_DNS_MX:-$host}"
    printf '  2) TXT    %s.  ->  %s\n' "$MAILU_DOMAIN" "${MC_DNS_SPF:-v=spf1 mx ~all}"
    printf '  3) TXT    dkim._domainkey.%s.  ->  %s\n' "$MAILU_DOMAIN" "${MC_DKIM_RECORD:-<在 Mailu 后台或中台点击“生成 DKIM 密钥”后获取>}"
    printf '  4) TXT    _dmarc.%s.  ->  %s\n' "$MAILU_DOMAIN" "${MC_DNS_DMARC:-v=DMARC1; p=quarantine; rua=mailto:postmaster@$MAILU_DOMAIN}"
    printf '  5) 主机名 A 记录: %s  ->  本机公网 IP\n' "$host"
    printf '\n'
    printf '%s在邮件中台里接入这台服务器%s\n' "$MC_C_BOLD" "$MC_C_RESET"
    printf '  1) 打开中台 -> 邮件服务器 -> 添加服务器\n'
    printf '  2) 访问地址填 https://%s ，认证方式选 “API 令牌”\n' "$host"
    printf '  3) API 令牌粘贴: %s\n' "$MAILU_API_TOKEN"
    printf '  4) 保存后点击“检查连接”，再点击“同步”，即可管理域名/账号/别名\n'
    printf '\n'
    printf '%s常用命令%s\n' "$MC_C_BOLD" "$MC_C_RESET"
    printf '  查看状态 : cd %s && docker compose ps\n' "$MAILU_ROOT"
    printf '  查看日志 : cd %s && docker compose logs -f --tail=100\n' "$MAILU_ROOT"
    printf '  重启服务 : cd %s && docker compose restart\n' "$MAILU_ROOT"
    printf '  备份数据 : bash %s/bin/mailu-backup.sh\n' "${MAILU_SELF_DIR:-.}"
    printf '  更新版本 : bash %s/bin/mailu-update.sh\n' "${MAILU_SELF_DIR:-.}"
    printf '  卸载     : bash %s/bin/mailu-uninstall.sh\n' "${MAILU_SELF_DIR:-.}"
    printf '\n'
    if [ "${MAILU_TLS_FLAVOR:-}" = "letsencrypt" ]; then
        printf '%s提示%s: 首次访问 https://%s 时 Mailu 会自动申请 Let'"'"'s Encrypt 证书，\n' "$MC_C_BOLD" "$MC_C_RESET" "$host"
        printf '      需要域名已解析到本机且 80 端口可从公网访问；如申请失败请查看 docker compose logs front。\n'
    fi
    printf '%s安全建议%s: 修改管理员密码、限制 22 端口来源、定期执行备份脚本。\n' "$MC_C_BOLD" "$MC_C_RESET"
    mc_hr
}