#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# TLS 证书准备：Let's Encrypt（Mailu 内置） / 自签 / 宿主机 certbot / 不加密
# ---------------------------------------------------------------------------

mc_primary_hostname() {
    printf '%s' "${MAILU_HOSTNAMES%%,*}"
}

mc_generate_selfsigned() {
    local dir="$MAILU_ROOT/certs"
    mkdir -p "$dir"
    if [ -f "$dir/cert.pem" ] && [ -f "$dir/key.pem" ]; then
        local days_left
        days_left="$(mc_cert_days_left "$dir/cert.pem")"
        if [ -n "$days_left" ] && [ "$days_left" -gt 7 ] 2>/dev/null; then
            mc_ok "已有自签证书（剩余 ${days_left} 天）"
            return 0
        fi
    fi
    mc_info "生成自签证书（有效期 825 天）"
    local names="" h
    local IFS=','
    for h in $MAILU_HOSTNAMES; do
        names="$names -d $h"
    done
    unset IFS
    if mc_is_dry_run; then
        mc_plain "[dry-run] openssl req -x509 ... $names"
        return 0
    fi
    # shellcheck disable=SC2086
    openssl req -x509 -nodes -newkey rsa:2048 -days 825 -sha256 \
        -subj "/CN=$(mc_primary_hostname)" \
        -addext "subjectAltName=DNS:$(printf '%s' "$MAILU_HOSTNAMES" | sed 's/,/,DNS:/g')" \
        $names -keyout "$dir/key.pem" -out "$dir/cert.pem" >/dev/null 2>&1 \
        || openssl req -x509 -nodes -newkey rsa:2048 -days 825 -sha256 \
            -subj "/CN=$(mc_primary_hostname)" \
            -keyout "$dir/key.pem" -out "$dir/cert.pem" >/dev/null 2>&1 \
        || mc_die "自签证书生成失败，请检查 openssl"
    chmod 600 "$dir/key.pem" 2>/dev/null || true
    mc_ok "自签证书已生成: $dir/cert.pem"
}

mc_cert_days_left() {
    local cert="$1"
    [ -f "$cert" ] || return 0
    local end end_ts now_ts
    end="$(openssl x509 -enddate -noout -in "$cert" 2>/dev/null | cut -d= -f2)" || return 0
    [ -n "$end" ] || return 0
    if end_ts="$(date -d "$end" +%s 2>/dev/null)"; then :; else
        end_ts="$(date -j -f '%b %d %H:%M:%S %Y %Z' "$end" +%s 2>/dev/null)" || return 0
    fi
    now_ts="$(date +%s)"
    printf '%s' "$(( (end_ts - now_ts) / 86400 ))"
}

mc_certbot_install() {
    mc_cmd_exists certbot && return 0
    mc_step "安装 certbot"
    mc_pkg_install certbot
    if ! mc_cmd_exists certbot; then
        mc_warn "包管理器未提供 certbot，尝试 snap"
        if mc_cmd_exists snap; then
            mc_run snap install --classic certbot || true
        fi
    fi
    mc_cmd_exists certbot
}

mc_certbot_obtain() {
    local dir="$MAILU_ROOT/certs" email="$MAILU_CERTBOT_EMAIL" host
    host="$(mc_primary_hostname)"
    mc_step "使用 certbot 申请 Let's Encrypt 证书（$host）"
    if mc_is_dry_run; then
        mc_plain "[dry-run] certbot certonly --standalone -d $host"
        return 0
    fi
    if ! mc_cmd_exists certbot; then
        mc_warn "certbot 不可用，跳过证书申请（将改用自签证书）"
        return 1
    fi
    local args=""
    local IFS=','
    for h in $MAILU_HOSTNAMES; do
        args="$args -d $h"
    done
    unset IFS
    # shellcheck disable=SC2086
    if certbot certonly --standalone --non-interactive --agree-tos --keep-until-expiring \
        --email "$email" --preferred-challenges http $args; then
        mc_ok "证书申请成功，复制到 $dir"
        mkdir -p "$dir"
        local le="/etc/letsencrypt/live/$host"
        if [ -d "$le" ]; then
            cp -f "$le/fullchain.pem" "$dir/cert.pem"
            cp -f "$le/privkey.pem" "$dir/key.pem"
            chmod 600 "$dir/key.pem" 2>/dev/null || true
            mc_install_renew_hook
            return 0
        fi
    fi
    mc_warn "certbot 申请失败（80 端口可能被占用或域名未解析），改用自签证书"
    return 1
}

mc_install_renew_hook() {
    local hook="/etc/letsencrypt/renewal-hooks/deploy/mailu-console-reload.sh"
    [ -d "$(dirname "$hook")" ] || return 0
    if mc_is_dry_run; then
        return 0
    fi
    cat >"$hook" <<HOOK
#!/usr/bin/env bash
# 由 Mailu 部署脚本生成：证书续期后复制到 Mailu 证书目录并重启前端
set -e
HOST="$(mc_primary_hostname)"
if [ -d "/etc/letsencrypt/live/\$HOST" ]; then
    cp -f "/etc/letsencrypt/live/\$HOST/fullchain.pem" "$MAILU_ROOT/certs/cert.pem"
    cp -f "/etc/letsencrypt/live/\$HOST/privkey.pem" "$MAILU_ROOT/certs/key.pem"
    chmod 600 "$MAILU_ROOT/certs/key.pem"
fi
cd "$MAILU_ROOT" && docker compose restart front >/dev/null 2>&1 || true
HOOK
    chmod +x "$hook"
    if mc_cmd_exists systemctl; then
        systemctl enable --now certbot.timer >/dev/null 2>&1 || true
    fi
    mc_ok "已安装证书续期钩子: $hook"
}

# 返回 Mailu 需要的 TLS_FLAVOR 值
mc_tls_prepare() {
    local mode="${MAILU_TLS:-letsencrypt}"
    mc_step "准备 TLS（模式: ${mode}）"
    case "$mode" in
        letsencrypt)
            MAILU_TLS_FLAVOR="letsencrypt"
            mc_info "Mailu 内置 certbot 会自动向 Let's Encrypt 申请证书（需要 80 端口可从公网访问）"
            mc_ensure_selfsigned_bootstrap
            ;;
        mail-letsencrypt)
            MAILU_TLS_FLAVOR="mail-letsencrypt"
            mc_ensure_selfsigned_bootstrap
            ;;
        mail)
            MAILU_TLS_FLAVOR="mail"
            mc_ensure_selfsigned_bootstrap
            ;;
        certbot)
            MAILU_TLS_FLAVOR="cert"
            if ! mc_certbot_obtain; then
                mc_generate_selfsigned
            fi
            ;;
        cert|selfsigned)
            MAILU_TLS_FLAVOR="cert"
            mc_generate_selfsigned
            ;;
        notls|none)
            MAILU_TLS_FLAVOR="notls"
            mc_warn "未启用 TLS：仅建议在完全隔离的内网测试环境使用"
            ;;
        *)
            mc_warn "未知 TLS 模式 '$mode'，回退为 letsencrypt"
            MAILU_TLS_FLAVOR="letsencrypt"
            ;;
    esac
    export MAILU_TLS_FLAVOR
}

# letsencrypt 模式下也准备一份自签证书，避免申请失败时 nginx 无法启动
mc_ensure_selfsigned_bootstrap() {
    if [ -f "$MAILU_ROOT/certs/cert.pem" ] && [ -f "$MAILU_ROOT/certs/key.pem" ]; then
        return 0
    fi
    mc_generate_selfsigned
}