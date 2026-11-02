#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# 防火墙放行（ufw / firewalld / iptables / nftables / 无防火墙）
# ---------------------------------------------------------------------------

MC_MAIL_PORTS_TCP="25 80 110 143 443 465 587 993 995 4190"
MC_MAIL_PORTS_UDP="443"

mc_firewall_detect() {
    if [ -n "${MAILU_FIREWALL_MANAGER:-}" ] && [ "$MAILU_FIREWALL_MANAGER" != "auto" ]; then
        printf '%s' "$MAILU_FIREWALL_MANAGER"
        return
    fi
    if mc_cmd_exists ufw && ufw status 2>/dev/null | grep -qi "Status: active"; then
        printf 'ufw'
    elif mc_cmd_exists firewall-cmd && firewall-cmd --state >/dev/null 2>&1; then
        printf 'firewalld'
    elif mc_cmd_exists nft && nft list ruleset >/dev/null 2>&1; then
        printf 'nftables'
    elif mc_cmd_exists iptables; then
        printf 'iptables'
    else
        printf 'none'
    fi
}

mc_firewall_open() {
    if [ "${MAILU_CONFIGURE_FIREWALL:-yes}" != "yes" ]; then
        mc_info "按参数要求跳过防火墙配置"
        return 0
    fi
    local manager
    manager="$(mc_firewall_detect)"
    mc_step "配置防火墙（${manager}）"
    case "$manager" in
        ufw)
            mc_run ufw allow 22/tcp >/dev/null 2>&1 || true
            for port in $MC_MAIL_PORTS_TCP; do
                mc_run ufw allow "$port/tcp" >/dev/null 2>&1 || true
            done
            for port in $MC_MAIL_PORTS_UDP; do
                mc_run ufw allow "$port/udp" >/dev/null 2>&1 || true
            done
            mc_run ufw --force enable >/dev/null 2>&1 || true
            mc_ok "ufw 已放行邮件端口"
            ;;
        firewalld)
            mc_run firewall-cmd --permanent --add-service=smtp >/dev/null 2>&1 || true
            mc_run firewall-cmd --permanent --add-service=smtps >/dev/null 2>&1 || true
            mc_run firewall-cmd --permanent --add-service=imap >/dev/null 2>&1 || true
            mc_run firewall-cmd --permanent --add-service=imaps >/dev/null 2>&1 || true
            mc_run firewall-cmd --permanent --add-service=pop3 >/dev/null 2>&1 || true
            mc_run firewall-cmd --permanent --add-service=pop3s >/dev/null 2>&1 || true
            mc_run firewall-cmd --permanent --add-service=http >/dev/null 2>&1 || true
            mc_run firewall-cmd --permanent --add-service=https >/dev/null 2>&1 || true
            for port in $MC_MAIL_PORTS_TCP; do
                mc_run firewall-cmd --permanent --add-port="${port}/tcp" >/dev/null 2>&1 || true
            done
            for port in $MC_MAIL_PORTS_UDP; do
                mc_run firewall-cmd --permanent --add-port="${port}/udp" >/dev/null 2>&1 || true
            done
            mc_run firewall-cmd --reload >/dev/null 2>&1 || true
            mc_ok "firewalld 已放行邮件端口"
            ;;
        nftables)
            if ! mc_is_dry_run; then
                local table="inet mailu"
                nft list table $table >/dev/null 2>&1 || nft add table inet mailu
                nft list chain inet mailu input >/dev/null 2>&1 || nft add chain inet mailu input '{ type filter hook input priority 0; policy accept; }'
                for port in $MC_MAIL_PORTS_TCP; do
                    nft add rule inet mailu input tcp dport "$port" accept 2>/dev/null || true
                done
                nft add rule inet mailu input udp dport 443 accept 2>/dev/null || true
            fi
            mc_ok "nftables 已放行邮件端口"
            ;;
        iptables)
            for port in $MC_MAIL_PORTS_TCP; do
                mc_run iptables -C INPUT -p tcp --dport "$port" -j ACCEPT >/dev/null 2>&1 || mc_run iptables -I INPUT -p tcp --dport "$port" -j ACCEPT >/dev/null 2>&1 || true
            done
            for port in $MC_MAIL_PORTS_UDP; do
                mc_run iptables -C INPUT -p udp --dport "$port" -j ACCEPT >/dev/null 2>&1 || mc_run iptables -I INPUT -p udp --dport "$port" -j ACCEPT >/dev/null 2>&1 || true
            done
            mc_ok "iptables 已放行邮件端口"
            ;;
        none)
            mc_warn "未检测到防火墙管理工具（云服务商安全组仍需手动放行 25/80/443/465/587/993/995）"
            ;;
        *)
            mc_warn "未识别的防火墙类型: $manager"
            ;;
    esac
    mc_info "所需放行端口: 25(收信) 80/443(网页与证书) 110/995(POP3) 143/993(IMAP) 465/587(发信) 4190(Sieve)"
}

mc_sysctl_tuning() {
    mc_step "内核参数优化"
    if mc_is_dry_run; then
        mc_plain "[dry-run] 写入 /etc/sysctl.d/99-mailu.conf"
        return 0
    fi
    if [ -d /etc/sysctl.d ]; then
        cat >/etc/sysctl.d/99-mailu.conf <<'SYSCTL'
# Mailu 邮件服务器推荐参数（由部署脚本生成）
fs.file-max = 2097152
net.core.somaxconn = 1024
net.ipv4.tcp_syncookies = 1
net.ipv4.tcp_fin_timeout = 30
net.ipv4.ip_local_port_range = 10240 65000
vm.max_map_count = 262144
SYSCTL
        if mc_cmd_exists sysctl; then
            sysctl -p /etc/sysctl.d/99-mailu.conf >/dev/null 2>&1 || mc_warn "sysctl 应用失败（不影响邮件系统运行）"
        fi
        mc_ok "内核参数已写入 /etc/sysctl.d/99-mailu.conf"
    else
        mc_warn "系统没有 /etc/sysctl.d，跳过内核参数优化"
    fi
}