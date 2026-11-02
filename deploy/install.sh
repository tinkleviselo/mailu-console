#!/usr/bin/env bash
# ===========================================================================
#  Mailu 邮件系统一键部署脚本
#  兼容: Debian/Ubuntu, RHEL/CentOS/Rocky/AlmaLinux/Oracle/Fedora/Amazon Linux,
#        openSUSE/SLES, Alpine, Arch
#  功能: 识别系统 -> 安装 Docker -> 下载 Mailu 安装包 -> 生成配置 ->
#        TLS 证书 -> 防火墙 -> 启动容器 -> 初始化管理员/域名/DKIM -> 输出报告
#  用法: bash install.sh --domain example.com --hostname mail.example.com --yes
# ===========================================================================
set -uo pipefail

# --- 内置默认参数（生成脚本会在文件头部覆盖其中任意一项） -------------------
: "${MAILU_LANG:=zh}"
: "${MAILU_DOMAIN:=}"
: "${MAILU_HOSTNAMES:=}"
: "${MAILU_TLS:=letsencrypt}"
: "${MAILU_ADMIN_USER:=admin}"
: "${MAILU_ADMIN_PASS:=}"
: "${MAILU_POSTMASTER:=postmaster}"
: "${MAILU_WEBMAIL:=snappymail}"
: "${MAILU_WEBMAIL_IMAGE:=}"
: "${MAILU_ADMIN_GUI:=true}"
: "${MAILU_API:=true}"
: "${MAILU_API_TOKEN:=}"
: "${MAILU_VERSION:=2024.06.61}"
: "${MAILU_MIRROR_PREFIX:=}"
: "${MAILU_DOCKER_ORG:=ghcr.io/mailu}"
: "${MAILU_RESOLVER:=yes}"
: "${MAILU_DNS_UPSTREAM:=9.9.9.9}"
: "${MAILU_DNS_FALLBACK:=1.1.1.1}"
: "${MAILU_ROOT:=/mailu}"
: "${MAILU_SUBNET:=192.168.203.0/24}"
: "${MAILU_SECRET_KEY:=}"
: "${MAILU_BIND_ADDRESS4:=0.0.0.0}"
: "${MAILU_SITE_NAME:=Mailu Mail}"
: "${MAILU_WEBSITE:=https://mailu.io}"
: "${MAILU_MESSAGE_SIZE_LIMIT:=52428800}"
: "${MAILU_MESSAGE_RATELIMIT:=200/day}"
: "${MAILU_ANTIVIRUS:=none}"
: "${MAILU_WEBDAV:=none}"
: "${MAILU_FETCHMAIL_ENABLED:=false}"
: "${MAILU_FETCHMAIL_DELAY:=600}"
: "${MAILU_RELAYNETS:=}"
: "${MAILU_RELAYHOST:=}"
: "${MAILU_RECIPIENT_DELIMITER:=+}"
: "${MAILU_DMARC_RUA:=postmaster}"
: "${MAILU_DMARC_RUF:=postmaster}"
: "${MAILU_WELCOME:=false}"
: "${MAILU_COMPRESSION:=}"
: "${MAILU_COMPOSE_PROJECT_NAME:=mailu}"
: "${MAILU_REAL_IP_HEADER:=}"
: "${MAILU_REAL_IP_FROM:=}"
: "${MAILU_REJECT_UNLISTED_RECIPIENT:=yes}"
: "${MAILU_TIMEZONE:=Etc/UTC}"
: "${MAILU_SPAM_THRESHOLD:=80}"
: "${MAILU_AUTH_RATELIMIT_IP:=50}"
: "${MAILU_AUTH_RATELIMIT_USER:=100}"
: "${MAILU_DISABLE_STATISTICS:=True}"
: "${MAILU_INSTALL_DOCKER:=yes}"
: "${MAILU_CONFIGURE_FIREWALL:=yes}"
: "${MAILU_FIREWALL_MANAGER:=auto}"
: "${MAILU_CERTBOT_EMAIL:=}"
: "${MAILU_COUNTRY:=CN}"
: "${MAILU_CONSOLE_URL:=}"
: "${MAILU_DEFAULT_QUOTA_MB:=2048}"
: "${MAILU_BACKUP_DIR:=/var/backups/mailu}"
: "${MAILU_BACKUP_KEEP:=7}"
: "${MAILU_DRY_RUN:=no}"
: "${MAILU_ASSUME_YES:=no}"
: "${MAILU_TLS_FLAVOR:=}"
: ${MAILU_ACTION:=install}
: "${MAILU_NEW_VERSION:=}"
: "${MAILU_PURGE:=no}"
: "${MAILU_RESTORE_FILE:=}"

# --- 独立包模式：加载本地库（单文件模式时函数已存在于上方） -----------------
MAILU_SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" && pwd)"
export MAILU_SELF_DIR
if [ -f "${MAILU_SELF_DIR}/lib/common.sh" ]; then
    # shellcheck source=lib/common.sh
    . "${MAILU_SELF_DIR}/lib/common.sh"
    for mc_lib in docker firewall tls mailu report; do
        if [ -f "${MAILU_SELF_DIR}/lib/${mc_lib}.sh" ]; then
            # shellcheck disable=SC1090
            . "${MAILU_SELF_DIR}/lib/${mc_lib}.sh"
        fi
    done
fi

if ! command -v mc_detect_os >/dev/null 2>&1; then
    echo "错误: 部署库文件缺失（lib/*.sh），请使用完整的安装包或单文件脚本。" >&2
    exit 1
fi

mc_usage() {
    cat <<USAGE
Mailu 邮件系统一键部署脚本 v${MAILU_VERSION}

用法:
  bash install.sh --domain example.com --hostname mail.example.com --yes
  bash install.sh --action status
  bash install.sh --action backup
  bash install.sh --action update [--new-version 2024.06.61]
  bash install.sh --action uninstall [--purge]
  bash install.sh --dry-run --domain example.com --hostname mail.example.com

常用参数:
  --domain DOMAIN          主邮件域名（必填）
  --hostname HOSTS         邮件服务器主机名，多个用逗号分隔（必填）
  --tls MODE               letsencrypt | certbot | cert | notls （默认 letsencrypt）
  --admin-user USER        管理员账号前缀（默认 admin）
  --admin-pass PASS        管理员密码（默认随机生成）
  --api-token TOKEN        启用 REST API 并设置令牌（默认随机生成，中台对接必需）
  --webmail TYPE           snappymail | roundcube | none
  --version VER            Mailu 版本标签（默认 ${MAILU_VERSION}）
  --mirror-prefix URL      GitHub 下载加速前缀，例如 https://ghproxy.net/
  --docker-org ORG         镜像前缀（默认 ghcr.io/mailu，国内可换镜像仓库）
  --root DIR               安装目录（默认 /mailu）
  --subnet CIDR            容器网段（默认 192.168.203.0/24）
  --email ADDR             证书通知邮箱（默认 admin@主域名）
  --site-name NAME         网页标题
  --timezone TZ            容器时区（默认 Etc/UTC）
  --no-docker              不自动安装 Docker
  --no-resolver            不启用内置 unbound 解析器（需自备 DNSSEC 校验型 DNS）
  --dns-upstream IP        解析器/容器使用的上游 DNS（默认 9.9.9.9）
  --no-firewall            不修改防火墙
  --firewall MANAGER       auto|ufw|firewalld|iptables|nftables|none
  --no-admin               不启用 Mailu 管理后台
  --no-api                 不启用 REST API（中台将无法管理该服务器）
  --antivirus clamav       启用 ClamAV 杀毒（内存占用大）
  --quota MB               默认账号配额提示值（写入中台参考）
  --yes, -y                非交互模式（自动确认）
  --dry-run                只打印将执行的操作
  --status                 查看运行状态
  --reconfigure            按当前参数重新生成配置并重启
  --backup / --action backup
  --restore FILE           从备份恢复
  --uninstall [--purge]    停止并删除容器（--purge 同时删除数据目录）
  -h, --help               显示本帮助
USAGE
}

mc_parse_args() {
    while [ "$#" -gt 0 ]; do
        case "$1" in
            --domain|--domain=*) MAILU_DOMAIN="${1#*=}"; [ "$1" = "--domain" ] && { shift; MAILU_DOMAIN="${1:-}"; } ;;
            --hostname|--hostnames) shift; MAILU_HOSTNAMES="${1:-}" ;;
            --hostname=*|--hostnames=*) MAILU_HOSTNAMES="${1#*=}" ;;
            --tls) shift; MAILU_TLS="${1:-}" ;;
            --tls=*) MAILU_TLS="${1#*=}" ;;
            --admin-user) shift; MAILU_ADMIN_USER="${1:-}" ;;
            --admin-user=*) MAILU_ADMIN_USER="${1#*=}" ;;
            --admin-pass) shift; MAILU_ADMIN_PASS="${1:-}" ;;
            --admin-pass=*) MAILU_ADMIN_PASS="${1#*=}" ;;
            --postmaster) shift; MAILU_POSTMASTER="${1:-}" ;;
            --api-token) shift; MAILU_API_TOKEN="${1:-}" ;;
            --api-token=*) MAILU_API_TOKEN="${1#*=}" ;;
            --webmail) shift; MAILU_WEBMAIL="${1:-}" ;;
            --webmail-image) shift; MAILU_WEBMAIL_IMAGE="${1:-}" ;;
            --webmail=*) MAILU_WEBMAIL="${1#*=}" ;;
            --webmail-image=*) MAILU_WEBMAIL_IMAGE="${1#*=}" ;;
            --version) shift; MAILU_VERSION="${1:-}" ;;
            --version=*) MAILU_VERSION="${1#*=}" ;;
            --new-version) shift; MAILU_NEW_VERSION="${1:-}" ;;
            --mirror-prefix) shift; MAILU_MIRROR_PREFIX="${1:-}" ;;
            --mirror-prefix=*) MAILU_MIRROR_PREFIX="${1#*=}" ;;
            --docker-org) shift; MAILU_DOCKER_ORG="${1:-}" ;;
            --docker-org=*) MAILU_DOCKER_ORG="${1#*=}" ;;
            --root) shift; MAILU_ROOT="${1:-}" ;;
            --root=*) MAILU_ROOT="${1#*=}" ;;
            --subnet) shift; MAILU_SUBNET="${1:-}" ;;
            --subnet=*) MAILU_SUBNET="${1#*=}" ;;
            --email) shift; MAILU_CERTBOT_EMAIL="${1:-}" ;;
            --email=*) MAILU_CERTBOT_EMAIL="${1#*=}" ;;
            --site-name) shift; MAILU_SITE_NAME="${1:-}" ;;
            --site-name=*) MAILU_SITE_NAME="${1#*=}" ;;
            --timezone) shift; MAILU_TIMEZONE="${1:-}" ;;
            --timezone=*) MAILU_TIMEZONE="${1#*=}" ;;
            --quota) shift; MAILU_DEFAULT_QUOTA_MB="${1:-}" ;;
            --quota=*) MAILU_DEFAULT_QUOTA_MB="${1#*=}" ;;
            --antivirus) shift; MAILU_ANTIVIRUS="${1:-}" ;;
            --antivirus=*) MAILU_ANTIVIRUS="${1#*=}" ;;
            --bind) shift; MAILU_BIND_ADDRESS4="${1:-}" ;;
            --bind=*) MAILU_BIND_ADDRESS4="${1#*=}" ;;
            --firewall) shift; MAILU_FIREWALL_MANAGER="${1:-}" ;;
            --firewall=*) MAILU_FIREWALL_MANAGER="${1#*=}" ;;
            --no-docker) MAILU_INSTALL_DOCKER="no" ;;
            --no-resolver) MAILU_RESOLVER="no" ;;
            --dns-upstream) shift; MAILU_DNS_UPSTREAM="${1:-9.9.9.9}" ;;
            --dns-upstream=*) MAILU_DNS_UPSTREAM="${1#*=}" ;;
            --no-firewall) MAILU_CONFIGURE_FIREWALL="no" ;;
            --no-admin) MAILU_ADMIN_GUI="false" ;;
            --no-api) MAILU_API="false" ;;
            --webdav) MAILU_WEBDAV="radicale" ;;
            --backup-dir) shift; MAILU_BACKUP_DIR="${1:-}" ;;
            --yes|-y|--non-interactive) MAILU_ASSUME_YES="yes" ;;
            --dry-run) MAILU_DRY_RUN="yes" ;;
            --status) MAILU_ACTION="status" ;;
            --backup) MAILU_ACTION="backup" ;;
            --update|--upgrade) MAILU_ACTION="update" ;;
            --restore) MAILU_ACTION="restore"; shift; MAILU_RESTORE_FILE="${1:-}" ;;
            --restore=*) MAILU_ACTION="restore"; MAILU_RESTORE_FILE="${1#*=}" ;;
            --uninstall) MAILU_ACTION="uninstall" ;;
            --purge) MAILU_PURGE="yes" ;;
            --reconfigure) MAILU_ACTION="reconfigure" ;;
            --action) shift; MAILU_ACTION="${1:-install}" ;;
            --action=*) MAILU_ACTION="${1#*=}" ;;
            -h|--help) mc_usage; exit 0 ;;
            *) mc_warn "忽略未知参数: $1" ;;
        esac
        shift
    done
    export MAILU_ASSUME_YES MAILU_DRY_RUN
}

mc_prompt_missing() {
    if [ -z "$MAILU_DOMAIN" ]; then
        if [ -t 0 ] && [ "${MAILU_ASSUME_YES}" != "yes" ]; then
            printf '请输入主邮件域名 (例如 example.com): '
            read -r MAILU_DOMAIN || MAILU_DOMAIN=""
        fi
    fi
    if [ -z "$MAILU_HOSTNAMES" ]; then
        if [ -t 0 ] && [ "${MAILU_ASSUME_YES}" != "yes" ]; then
            printf '请输入邮件服务器主机名 (例如 mail.%s): ' "$MAILU_DOMAIN"
            read -r MAILU_HOSTNAMES || MAILU_HOSTNAMES=""
            [ -n "$MAILU_HOSTNAMES" ] || MAILU_HOSTNAMES="mail.${MAILU_DOMAIN}"
        fi
    fi
    export MAILU_DOMAIN MAILU_HOSTNAMES
}

mc_validate_params() {
    [ -n "$MAILU_DOMAIN" ] || mc_die "缺少主域名，请使用 --domain example.com"
    [ -n "$MAILU_HOSTNAMES" ] || mc_die "缺少邮件主机名，请使用 --hostname mail.example.com"
    case "$MAILU_DOMAIN" in
        *.*) : ;;
        *) mc_die "主域名格式不正确: $MAILU_DOMAIN" ;;
    esac
    case "$MAILU_HOSTNAMES" in
        *.*) : ;;
        *) mc_die "主机名格式不正确: $MAILU_HOSTNAMES" ;;
    esac
    case "$MAILU_ROOT" in
        /|"") mc_die "安装目录不能是根目录" ;;
    esac
    if [ -z "$MAILU_ADMIN_PASS" ]; then
        MAILU_ADMIN_PASS="$(mc_random_password 18)"
        mc_info "已自动生成管理员密码"
    fi
    if [ -z "$MAILU_SECRET_KEY" ]; then
        MAILU_SECRET_KEY="$(mc_random_hex 16)"
    fi
    if [ "${MAILU_API}" = "true" ] && [ -z "$MAILU_API_TOKEN" ]; then
        MAILU_API_TOKEN="$(mc_random_hex 16)"
    fi
    if [ -z "$MAILU_CERTBOT_EMAIL" ]; then
        MAILU_CERTBOT_EMAIL="admin@${MAILU_DOMAIN}"
    fi
    export MAILU_ADMIN_PASS MAILU_SECRET_KEY MAILU_API_TOKEN MAILU_CERTBOT_EMAIL
}

mc_install_flow() {
    mc_print_banner
    if [ "${MAILU_INSTALL_DOCKER}" = "yes" ]; then
        mc_install_docker
    else
        mc_info "按参数要求跳过 Docker 自动安装"
        mc_docker_running || mc_die "Docker 未运行，请先安装并启动 Docker"
    fi
    mc_mailu_dirs
    mc_fetch_package
    mc_tls_prepare
    mc_write_compose
    mc_write_env
    mc_firewall_open
    mc_sysctl_tuning
    mc_compose_pull
    mc_compose_up
    mc_wait_containers 40
    mc_verify_stack || true
    mc_bootstrap_admin
    mc_fetch_dkim_records
    mc_save_config
    mc_print_summary
    mc_info "完整日志: ${MAILU_LOG}"
}

mc_main() {
    mc_parse_args "$@"
    case "${MAILU_ACTION}" in
        install|reconfigure)
            mc_require_root "$@"
            mc_detect_os
            mc_ensure_base_tools
            if [ "${MAILU_ACTION}" = "reconfigure" ] && [ -z "$MAILU_DOMAIN" ]; then
                mc_load_saved_config
            fi
            mc_prompt_missing
            mc_validate_params
            if [ "${MAILU_ACTION}" = "reconfigure" ]; then
                mc_require_root
                mc_mailu_dirs
                mc_tls_prepare
                mc_write_compose
                mc_write_env
                mc_compose_up
                mc_wait_containers
                mc_save_config
                mc_ok "配置已重新生成并重启容器"
                mc_status
                return 0
            fi
            if [ "${MAILU_ASSUME_YES}" != "yes" ]; then
                mc_print_banner
                mc_confirm "即将在 ${MAILU_ROOT} 安装 Mailu（域名 ${MAILU_DOMAIN}），继续？" || mc_die "已取消"
            fi
            mc_install_flow
            ;;
        status)
            mc_status
            ;;
        backup)
            mc_detect_os
            mc_backup
            ;;
        restore)
            mc_require_root
            mc_restore "${MAILU_RESTORE_FILE:-}"
            ;;
        update)
            mc_require_root
            mc_detect_os
            mc_ensure_base_tools
            if [ -z "$MAILU_DOMAIN" ]; then
                mc_load_saved_config
            fi
            mc_prompt_missing
            mc_validate_params
            mc_update
            ;;
        uninstall)
            mc_require_root
            mc_detect_os
            if [ -z "$MAILU_DOMAIN" ]; then
                mc_load_saved_config || true
            fi
            mc_uninstall "${MAILU_PURGE:-no}"
            ;;
        *)
            mc_usage
            exit 1
            ;;
    esac
}

# 仅在被直接执行时运行主流程；被 source 时只加载函数（便于自动化测试）
if [ "${BASH_SOURCE[0]:-}" = "$0" ] || [ -z "${BASH_SOURCE[0]:-}" ] || [ "${MAILU_FORCE_MAIN:-no}" = "yes" ]; then
    mc_main "$@"
fi
