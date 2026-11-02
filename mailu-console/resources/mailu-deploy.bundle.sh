#!/usr/bin/env bash
# ===========================================================================
#  Mailu 一键部署脚本（单文件版，自动生成，请勿手工编辑）
#  生成时间: 2026-10-22 18:06:34
#  说明    : 该文件由 build/package.py 合并 deploy/lib/*.sh 与 deploy/install.sh 生成。
#            中台的"部署中心"会在此基础上替换下面的参数块，生成带参数的定制脚本。
#  用法    : bash mailu-deploy.bundle.sh --domain example.com --hostname mail.example.com --yes
# ===========================================================================

##MC_PARAMS##


# ---------------------------------------------------------------------------

# 合并自 deploy/lib/common.sh

# ---------------------------------------------------------------------------

# ---------------------------------------------------------------------------
# Mailu 部署脚本 - 公共库 (日志 / 系统识别 / 包管理 / 下载 / 参数)
# 该文件既可以单独 source，也会被合并进单文件部署脚本。
# ---------------------------------------------------------------------------

# 默认值（可被生成脚本中的参数块覆盖）
: "${MAILU_LANG:=zh}"
: "${MAILU_LOG:=/var/log/mailu-deploy.log}"
: "${MAILU_DRY_RUN:=no}"
: "${MAILU_ASSUME_YES:=no}"

# --- 颜色 ---------------------------------------------------------------
if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
    MC_C_RESET=$'\033[0m'; MC_C_RED=$'\033[31m'; MC_C_GREEN=$'\033[32m'
    MC_C_YELLOW=$'\033[33m'; MC_C_BLUE=$'\033[34m'; MC_C_BOLD=$'\033[1m'
else
    MC_C_RESET=''; MC_C_RED=''; MC_C_GREEN=''; MC_C_YELLOW=''; MC_C_BLUE=''; MC_C_BOLD=''
fi

mc_is_dry_run() {
    [ "${MAILU_DRY_RUN}" = "yes" ] || [ "${MAILU_DRY_RUN}" = "true" ] || [ "${MAILU_DRY_RUN}" = "1" ]
}

mc_log_file() {
    if [ -w "$(dirname "$MAILU_LOG")" ] 2>/dev/null || [ -w "$MAILU_LOG" ] 2>/dev/null; then
        printf '%s\n' "$1" >>"$MAILU_LOG" 2>/dev/null || true
    fi
}

mc_log() {
    local level="$1"; shift
    local stamp msg
    stamp="$(date '+%Y-%m-%d %H:%M:%S')"
    msg="[$stamp] [$level] $*"
    mc_log_file "$msg"
    case "$level" in
        INFO) printf '%s[信息]%s %s\n' "$MC_C_BLUE" "$MC_C_RESET" "$*" ;;
        OK)   printf '%s[成功]%s %s\n' "$MC_C_GREEN" "$MC_C_RESET" "$*" ;;
        WARN) printf '%s[警告]%s %s\n' "$MC_C_YELLOW" "$MC_C_RESET" "$*" >&2 ;;
        ERR)  printf '%s[错误]%s %s\n' "$MC_C_RED" "$MC_C_RESET" "$*" >&2 ;;
        STEP) printf '\n%s==>%s %s%s%s\n' "$MC_C_BLUE" "$MC_C_RESET" "$MC_C_BOLD" "$*" "$MC_C_RESET" ;;
        *)    printf '%s\n' "$*" ;;
    esac
}

mc_info() { mc_log INFO "$@"; }
mc_ok()   { mc_log OK "$@"; }
mc_warn() { mc_log WARN "$@"; }
mc_err()  { mc_log ERR "$@"; }
mc_step() { mc_log STEP "$@"; }
mc_plain() { mc_log_file "[$(date '+%Y-%m-%d %H:%M:%S')] [OUT] $*"; printf '%s\n' "$*"; }

mc_die() {
    mc_err "$@"
    mc_err "安装已中止。日志文件: ${MAILU_LOG}"
    exit 1
}

mc_hr() {
    printf '%s\n' "---------------------------------------------------------------------------"
}

# --- 交互 ---------------------------------------------------------------
mc_confirm() {
    local prompt="$1" reply=""
    if [ "${MAILU_ASSUME_YES}" = "yes" ]; then
        return 0
    fi
    if [ ! -t 0 ]; then
        mc_warn "非交互环境，默认继续: $prompt"
        return 0
    fi
    printf '%s [y/N]: ' "$prompt"
    read -r reply || reply=""
    case "$reply" in
        y|Y|yes|YES) return 0 ;;
        *) return 1 ;;
    esac
}

mc_cmd_exists() {
    command -v "$1" >/dev/null 2>&1
}

mc_run() {
    if mc_is_dry_run; then
        mc_plain "[dry-run] $*"
        return 0
    fi
    mc_log_file "[$(date '+%Y-%m-%d %H:%M:%S')] [EXEC] $*"
    "$@"
}

mc_random_hex() {
    local bytes="${1:-16}" out="" i=0
    if mc_cmd_exists openssl; then
        out="$(openssl rand -hex "$bytes" 2>/dev/null || true)"
    fi
    if [ -z "$out" ] && [ -r /dev/urandom ] && mc_cmd_exists od; then
        out="$(od -An -tx1 -N "$bytes" /dev/urandom 2>/dev/null | tr -d ' \n' || true)"
    fi
    if [ -z "$out" ]; then
        # 最后兜底：bash 内置随机数 + 时间戳（强度较弱，仅保证流程不中断）
        mc_warn "未找到 openssl/od，改用内置随机数回退（建议安装 openssl 后重新生成密钥与密码）"
        while [ "$i" -lt "$bytes" ]; do
            out="$out$(printf '%04x' "$RANDOM")"
            i=$((i + 1))
        done
        out="${out:0:$((bytes * 2))}"
    fi
    printf '%s' "$out"
}

mc_random_password() {
    local len="${1:-18}" out="" raw="" hex="" i=0
    if mc_cmd_exists openssl; then
        raw="$(openssl rand -base64 64 2>/dev/null | tr -dc 'A-Za-z0-9!@#%^&*_-+=' || true)"
        out="${raw:0:$len}"
    fi
    if [ "${#out}" -lt "$len" ]; then
        hex="$(mc_random_hex 64)"
        out=""
        while [ "${#out}" -lt "$len" ] && [ "$i" -lt 64 ]; do
            out="$out${hex:$((i * 2)):2}"
            i=$((i + 1))
        done
        out="${out:0:$len}"
    fi
    printf '%s' "$out"
}

mc_require_root() {
    local uid=""
    if mc_cmd_exists id; then
        uid="$(id -u 2>/dev/null || echo '')"
    fi
    if [ "$uid" = "0" ] || [ "${MAILU_ALLOW_NONROOT:-no}" = "yes" ]; then
        return 0
    fi
    if mc_is_dry_run; then
        mc_warn "当前不是 root，但处于 --dry-run 模式，继续演示流程（不会做任何修改）"
        return 0
    fi
    if mc_cmd_exists sudo; then
        mc_warn "当前不是 root，脚本将尝试使用 sudo 重新执行"
        exec sudo -E bash "$0" "$@"
    fi
    mc_die "请使用 root 用户运行（或安装 sudo）: sudo bash $0"
}

# --- 模板渲染（纯 bash，避免 sed 特殊字符问题） --------------------------
# 用法: mc_render_template 源文件 目标文件 KEY=值 [KEY=值 ...]
mc_render_template() {
    local src="$1" dst="$2"
    shift 2
    [ -f "$src" ] || mc_die "模板文件不存在: $src"
    local line pair key val tmp before
    tmp="${dst}.tmp.$$"
    : >"$tmp"
    while IFS= read -r line || [ -n "$line" ]; do
        for pair in "$@"; do
            key="${pair%%=*}"
            val="${pair#*=}"
            # 使用字符串拼接逐次替换：不依赖各 shell 对替换串中 & 等字符的处理差异
            while :; do
                case "$line" in
                    *"@${key}@"*)
                        before="${line%%@${key}@*}"
                        line="${before}${val}${line#*@${key}@}"
                        ;;
                    *)
                        break
                        ;;
                esac
            done
        done
        printf '%s\n' "$line" >>"$tmp"
    done <"$src"
    mv -f "$tmp" "$dst"
}

# --- 系统识别 -----------------------------------------------------------
mc_detect_os() {
    MC_OS_ID="unknown"
    MC_OS_NAME="unknown"
    MC_OS_VERSION=""
    MC_OS_LIKE=""
    MC_PKG="none"
    MC_ARCH="$(uname -m 2>/dev/null || echo unknown)"
    MC_OS_FAMILY="unknown"

    if [ -r /etc/os-release ]; then
        # shellcheck disable=SC1091
        . /etc/os-release
        MC_OS_ID="${ID:-unknown}"
        MC_OS_NAME="${PRETTY_NAME:-${NAME:-$MC_OS_ID}}"
        MC_OS_VERSION="${VERSION_ID:-}"
        MC_OS_LIKE="${ID_LIKE:-}"
    elif [ -r /etc/redhat-release ]; then
        MC_OS_ID="rhel"
        MC_OS_NAME="$(cat /etc/redhat-release)"
    elif [ -r /etc/debian_version ]; then
        MC_OS_ID="debian"
        MC_OS_NAME="Debian $(cat /etc/debian_version)"
    fi

    case " $MC_OS_ID $MC_OS_LIKE " in
        *" debian "*|*" ubuntu "*) MC_OS_FAMILY="debian"; MC_PKG="apt" ;;
        *" rhel "*|*" fedora "*|*" centos "*) MC_OS_FAMILY="rhel" ;;
        *" suse "*|*" opensuse "*) MC_OS_FAMILY="suse"; MC_PKG="zypper" ;;
        *" alpine "*) MC_OS_FAMILY="alpine"; MC_PKG="apk" ;;
        *" arch "*) MC_OS_FAMILY="arch"; MC_PKG="pacman" ;;
    esac

    case "$MC_OS_ID" in
        debian|ubuntu|raspbian|linuxmint|pop|kali|deepin|uos|kylin) MC_OS_FAMILY="debian"; MC_PKG="apt" ;;
        centos|rhel|rocky|almalinux|ol|oracle|fedora|amzn|virtuozzo|cloudlinux) MC_OS_FAMILY="rhel" ;;
        opensuse*|sles|sled) MC_OS_FAMILY="suse"; MC_PKG="zypper" ;;
        alpine) MC_OS_FAMILY="alpine"; MC_PKG="apk" ;;
        arch|manjaro|endeavouros|garuda) MC_OS_FAMILY="arch"; MC_PKG="pacman" ;;
    esac

    if [ "$MC_OS_FAMILY" = "rhel" ]; then
        if mc_cmd_exists dnf; then MC_PKG="dnf"; else MC_PKG="yum"; fi
    fi
    if [ "$MC_PKG" = "none" ]; then
        if mc_cmd_exists apt-get; then MC_PKG="apt"; MC_OS_FAMILY="debian"
        elif mc_cmd_exists dnf; then MC_PKG="dnf"; MC_OS_FAMILY="rhel"
        elif mc_cmd_exists yum; then MC_PKG="yum"; MC_OS_FAMILY="rhel"
        elif mc_cmd_exists zypper; then MC_PKG="zypper"; MC_OS_FAMILY="suse"
        elif mc_cmd_exists apk; then MC_PKG="apk"; MC_OS_FAMILY="alpine"
        elif mc_cmd_exists pacman; then MC_PKG="pacman"; MC_OS_FAMILY="arch"
        fi
    fi

    export MC_OS_ID MC_OS_NAME MC_OS_VERSION MC_OS_LIKE MC_PKG MC_ARCH MC_OS_FAMILY
    mc_info "系统: ${MC_OS_NAME} (${MC_ARCH}) · 包管理器: ${MC_PKG} · 家族: ${MC_OS_FAMILY}"
}

mc_pkg_update() {
    mc_info "更新软件源索引..."
    case "$MC_PKG" in
        apt) mc_run env DEBIAN_FRONTEND=noninteractive apt-get update -qq ;;
        dnf) mc_run dnf -q makecache ;;
        yum) mc_run yum -q makecache ;;
        zypper) mc_run zypper -q --non-interactive refresh ;;
        apk) mc_run apk update -q ;;
        pacman) mc_run pacman -Sy --noconfirm --quiet ;;
        *) mc_warn "未知包管理器，跳过软件源更新" ;;
    esac
}

mc_pkg_install() {
    [ "$#" -gt 0 ] || return 0
    mc_info "安装依赖: $*"
    case "$MC_PKG" in
        apt) mc_run env DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "$@" ;;
        dnf) mc_run dnf install -y -q "$@" ;;
        yum) mc_run yum install -y -q "$@" ;;
        zypper) mc_run zypper --non-interactive --quiet install "$@" ;;
        apk) mc_run apk add --no-cache -q "$@" ;;
        pacman) mc_run pacman -S --noconfirm --needed --quiet "$@" ;;
        *) mc_warn "无法自动安装: $* （请手动安装）" ;;
    esac
}

# --- 下载 ---------------------------------------------------------------
mc_download() {
    local url="$1" dest="$2" tries="${3:-3}" i=0
    [ -n "$url" ] || return 1
    mkdir -p "$(dirname "$dest")" 2>/dev/null || true
    while [ "$i" -lt "$tries" ]; do
        i=$((i + 1))
        if mc_is_dry_run; then
            mc_plain "[dry-run] 下载 $url -> $dest"
            return 0
        fi
        if mc_cmd_exists curl; then
            if curl -fsSL --connect-timeout 15 --retry 2 --max-time 600 -o "$dest.part" "$url"; then
                mv -f "$dest.part" "$dest"
                return 0
            fi
        elif mc_cmd_exists wget; then
            if wget -q --timeout=30 --tries=2 -O "$dest.part" "$url"; then
                mv -f "$dest.part" "$dest"
                return 0
            fi
        else
            mc_err "缺少 curl 或 wget，无法下载"
            return 1
        fi
        mc_warn "下载失败（第 ${i} 次）: $url"
        sleep 2
    done
    rm -f "$dest.part" 2>/dev/null || true
    return 1
}

# 依次尝试多个地址，成功即返回
mc_download_first() {
    local dest="$1"; shift
    local url
    for url in "$@"; do
        [ -n "$url" ] || continue
        mc_info "尝试下载: $url"
        if mc_download "$url" "$dest"; then
            mc_ok "已下载: $(basename "$dest")"
            return 0
        fi
    done
    return 1
}

# 带镜像前缀的下载（国内 VPS 加速）
mc_mirror_urls() {
    local path="$1"
    if [ -n "${MAILU_MIRROR_PREFIX:-}" ]; then
        printf '%s\n' "${MAILU_MIRROR_PREFIX%/}/https://github.com/Mailu/Mailu/${path}"
    fi
    printf '%s\n' "https://github.com/Mailu/Mailu/${path}"
    printf '%s\n' "https://raw.githubusercontent.com/Mailu/Mailu/${path}"
    printf '%s\n' "https://gitee.com/mirrors/Mailu/raw/master/${path}"
}

mc_ensure_base_tools() {
    local missing=""
    local tool
    for tool in curl openssl; do
        mc_cmd_exists "$tool" || missing="$missing $tool"
    done
    if [ -n "$missing" ]; then
        mc_pkg_update
        # shellcheck disable=SC2086
        mc_pkg_install $missing ca-certificates
    fi
    if ! mc_cmd_exists curl && ! mc_cmd_exists wget; then
        mc_die "需要 curl 或 wget 才能下载安装包"
    fi
}

# ---------------------------------------------------------------------------

# 合并自 deploy/lib/docker.sh

# ---------------------------------------------------------------------------

# ---------------------------------------------------------------------------
# Docker / Docker Compose 安装与检测（多发行版兼容）
# ---------------------------------------------------------------------------

mc_docker_installed() {
    mc_cmd_exists docker
}

mc_compose_cmd() {
    # 输出可用的 compose 命令（docker compose 或 docker-compose）
    if docker compose version >/dev/null 2>&1; then
        printf 'docker compose'
    elif mc_cmd_exists docker-compose; then
        printf 'docker-compose'
    else
        printf ''
    fi
}

mc_compose_available() {
    [ -n "$(mc_compose_cmd)" ]
}

mc_docker_running() {
    if ! mc_docker_installed; then
        return 1
    fi
    if docker info >/dev/null 2>&1; then
        return 0
    fi
    return 1
}

mc_start_docker_service() {
    if mc_cmd_exists systemctl; then
        mc_run systemctl enable --now docker >/dev/null 2>&1 || mc_run systemctl start docker >/dev/null 2>&1 || true
    elif mc_cmd_exists rc-service; then
        mc_run rc-service docker start >/dev/null 2>&1 || true
        mc_run rc-update add docker default >/dev/null 2>&1 || true
    elif mc_cmd_exists service; then
        mc_run service docker start >/dev/null 2>&1 || true
    fi
    sleep 2
}

mc_install_docker() {
    if mc_docker_running && mc_compose_available; then
        mc_ok "Docker 已安装: $(docker --version 2>/dev/null)"
        return 0
    fi
    mc_step "安装 Docker 与 Compose 插件"

    if mc_is_dry_run; then
        mc_plain "[dry-run] 将根据系统 ${MC_OS_FAMILY} 安装 docker + compose"
        return 0
    fi

    # Alpine: docker + docker-cli-compose 由 apk 提供
    if [ "$MC_OS_FAMILY" = "alpine" ]; then
        mc_pkg_update
        mc_pkg_install docker docker-cli-compose curl openssl
        mc_start_docker_service
        if mc_docker_running; then
            mc_ok "Docker 安装完成 (Alpine)"
            return 0
        fi
        mc_warn "Alpine 下 Docker 未成功启动，继续尝试官方脚本"
    fi

    # Arch
    if [ "$MC_OS_FAMILY" = "arch" ]; then
        mc_pkg_update
        mc_pkg_install docker docker-compose
        mc_start_docker_service
        if mc_docker_running; then
            mc_ok "Docker 安装完成 (Arch)"
            return 0
        fi
    fi

    # openSUSE
    if [ "$MC_OS_FAMILY" = "suse" ]; then
        mc_pkg_update
        mc_pkg_install docker docker-compose
        mc_start_docker_service
        if mc_docker_running; then
            mc_ok "Docker 安装完成 (openSUSE)"
            return 0
        fi
    fi

    # Debian / RHEL 家族：优先使用发行版自带仓库（更稳），失败再走官方脚本
    if [ "$MC_OS_FAMILY" = "debian" ]; then
        mc_pkg_update
        mc_pkg_install ca-certificates curl gnupg lsb-release
        mc_pkg_install docker.io docker-compose-plugin || mc_pkg_install docker.io docker-compose || true
        mc_start_docker_service
    elif [ "$MC_OS_FAMILY" = "rhel" ]; then
        mc_pkg_update
        mc_pkg_install ca-certificates curl
        if [ "$MC_PKG" = "dnf" ]; then
            mc_run dnf install -y -q dnf-plugins-core >/dev/null 2>&1 || true
        fi
        mc_pkg_install docker docker-compose-plugin || mc_pkg_install docker docker-compose || mc_pkg_install moby-engine moby-compose || true
        mc_start_docker_service
    fi

    if mc_docker_running && mc_compose_available; then
        mc_ok "Docker 安装完成（发行版仓库）: $(docker --version 2>/dev/null)"
        return 0
    fi

    mc_warn "发行版仓库未提供可用的 Docker/Compose，改用 Docker 官方安装脚本"
    local script="/tmp/get-docker-$$.sh"
    if mc_download "https://get.docker.com" "$script"; then
        if ! mc_is_dry_run; then
            sh "$script" || mc_warn "Docker 官方脚本执行失败"
        fi
        rm -f "$script"
        mc_start_docker_service
    else
        mc_warn "无法下载 Docker 官方安装脚本（网络受限？）"
    fi

    if ! mc_docker_running; then
        mc_die "Docker 安装失败。请手动安装 Docker 后重新运行本脚本（可加 --no-docker 跳过自动安装）。"
    fi
    if ! mc_compose_available; then
        mc_die "未检测到 Docker Compose。请安装 docker-compose-plugin 后重新运行。"
    fi
    mc_ok "Docker 就绪: $(docker --version 2>/dev/null) / compose: $(mc_compose_cmd)"
}

# 全局 compose 快捷函数（在 $MAILU_ROOT 目录下执行）
mc_compose() {
    local dir="${1:-$MAILU_ROOT}"
    shift || true
    if mc_is_dry_run; then
        mc_plain "[dry-run] (cd $dir && docker compose $*)"
        return 0
    fi
    local cmd
    cmd="$(mc_compose_cmd)"
    [ -n "$cmd" ] || mc_die "未找到 docker compose 命令"
    ( cd "$dir" && $cmd "$@" )
}

# ---------------------------------------------------------------------------

# 合并自 deploy/lib/firewall.sh

# ---------------------------------------------------------------------------

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

# ---------------------------------------------------------------------------

# 合并自 deploy/lib/tls.sh

# ---------------------------------------------------------------------------

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

# ---------------------------------------------------------------------------

# 合并自 deploy/lib/mailu.sh

# ---------------------------------------------------------------------------

# ---------------------------------------------------------------------------
# Mailu 安装包下载 / 配置生成 / 容器启停 / API 初始化
# ---------------------------------------------------------------------------

mc_mailu_dirs() {
    mc_step "创建目录结构 ($MAILU_ROOT)"
    local dirs="certs data dkim mail mailqueue filter redis webmail overrides overrides/nginx overrides/dovecot overrides/postfix overrides/rspamd overrides/snappymail overrides/roundcube dav .cache"
    local d
    for d in $dirs; do
        if ! mc_is_dry_run; then
            mkdir -p "$MAILU_ROOT/$d"
        fi
    done
    mc_ok "目录已就绪"
}

# 下载 Mailu 官方发行包（作为“安装包”缓存），并保存官方模板供参考
mc_fetch_package() {
    mc_step "下载 Mailu 安装包 (版本 ${MAILU_VERSION})"
    local cache="$MAILU_ROOT/.cache"
    local tarball="$cache/mailu-${MAILU_VERSION}.tar.gz"
    local extracted="$cache/mailu-${MAILU_VERSION}"
    mkdir -p "$cache" 2>/dev/null || true

    if [ -d "$extracted" ] && [ -f "$extracted/setup/flavors/compose/docker-compose.yml" ]; then
        mc_ok "安装包已存在缓存: $extracted"
        MC_PACKAGE_DIR="$extracted"
        export MC_PACKAGE_DIR
        return 0
    fi

    local urls=""
    if [ -n "${MAILU_MIRROR_PREFIX:-}" ]; then
        urls="$urls ${MAILU_MIRROR_PREFIX%/}/https://github.com/Mailu/Mailu/archive/refs/tags/${MAILU_VERSION}.tar.gz"
        urls="$urls ${MAILU_MIRROR_PREFIX%/}/https://codeload.github.com/Mailu/Mailu/tar.gz/refs/tags/${MAILU_VERSION}"
    fi
    # 本地缓存优先（离线部署场景：packages/cache 随脚本包一起分发）
    if [ -f "${MAILU_SELF_DIR:-.}/packages/cache/mailu-${MAILU_VERSION}.tar.gz" ]; then
        cp -f "${MAILU_SELF_DIR}/packages/cache/mailu-${MAILU_VERSION}.tar.gz" "$tarball" 2>/dev/null \
            && mc_ok "使用随包分发的安装包缓存"
    fi
    urls="$urls https://github.com/Mailu/Mailu/archive/refs/tags/${MAILU_VERSION}.tar.gz"
    urls="$urls https://codeload.github.com/Mailu/Mailu/tar.gz/refs/tags/${MAILU_VERSION}"
    urls="$urls https://gitee.com/mirrors/Mailu/repository/archive/${MAILU_VERSION}.tar.gz"

    if [ ! -f "$tarball" ]; then
        # shellcheck disable=SC2086
        if ! mc_download_first "$tarball" $urls; then
            mc_warn "无法下载 Mailu 安装包（网络受限？）。将使用脚本内置配置模板，Docker 镜像仍会从镜像仓库拉取。"
            mc_warn "如需离线安装，请执行: bash packages/fetch-package.sh ${MAILU_VERSION} 后把 deploy 目录一起拷贝过来。"
            return 0
        fi
    fi

    mc_info "解压安装包..."
    if mc_is_dry_run; then
        return 0
    fi
    rm -rf "$cache/extract"
    mkdir -p "$cache/extract"
    if tar -xzf "$tarball" -C "$cache/extract" 2>/dev/null; then
        local first
        first="$(ls -1 "$cache/extract" 2>/dev/null | head -n 1)"
        if [ -n "$first" ]; then
            rm -rf "$extracted"
            mv "$cache/extract/$first" "$extracted"
            MC_PACKAGE_DIR="$extracted"
            export MC_PACKAGE_DIR
            mc_ok "安装包已解压: $extracted"
            if [ -f "$extracted/setup/flavors/compose/docker-compose.yml" ]; then
                cp -f "$extracted/setup/flavors/compose/docker-compose.yml" "$cache/official-docker-compose.yml.j2" 2>/dev/null || true
                cp -f "$extracted/setup/flavors/compose/mailu.env" "$cache/official-mailu.env.j2" 2>/dev/null || true
                mc_info "已保存官方模板到 $cache/（供对比与升级参考）"
            fi
        fi
    else
        mc_warn "安装包解压失败，将使用脚本内置的 compose 模板"
    fi
    return 0
}

# 镜像前缀与 webmail 镜像名（Mailu 2.x 使用统一的 webmail 镜像；1.x 使用 mailu/<webmail>）
mc_image_prefix() {
    case "${MAILU_VERSION}" in
        1.*) printf '%s' "mailu" ;;
        *) printf '%s' "${MAILU_DOCKER_ORG:-ghcr.io/mailu}" ;;
    esac
}

mc_webmail_image() {
    local org="$1"
    if [ -n "${MAILU_WEBMAIL_IMAGE:-}" ]; then
        printf '%s' "$MAILU_WEBMAIL_IMAGE"
        return 0
    fi
    case "${MAILU_VERSION}" in
        1.*) printf '%s/%s:%s' "$org" "${MAILU_WEBMAIL:-snappymail}" "${MAILU_VERSION}" ;;
        *) printf '%s/webmail:%s' "$org" "${MAILU_VERSION}" ;;
    esac
}

# 生成 docker-compose.yml（Mailu 2.x 服务结构，按参数裁剪服务）
mc_resolver_ip() {
    local subnet="${MAILU_SUBNET:-192.168.203.0/24}"
    local base="${subnet%/*}"
    base="${base%.*}"
    printf '%s.254' "$base"
}

# 输出 DNS 相关片段（resolver 模式时给每个服务加 depends_on + dns）
mc_dns_block() {
    if [ "${MAILU_RESOLVER:-yes}" != "yes" ]; then
        return 0
    fi
    printf '    dns:\n'
    printf '      - %s\n' "$(mc_resolver_ip)"
}

mc_write_compose() {
    local file="$MAILU_ROOT/docker-compose.yml"
    local org webmail_image resolver_ip
    org="$(mc_image_prefix)"
    webmail_image="$(mc_webmail_image "$org")"
    resolver_ip="$(mc_resolver_ip)"
    mc_step "生成 docker-compose.yml"
    if mc_is_dry_run; then
        mc_plain "[dry-run] 写入 $file"
        return 0
    fi
    {
        printf '%s\n' "# 由 Mailu 部署脚本生成 - $(date '+%Y-%m-%d %H:%M:%S')"
        printf '%s\n' "# Mailu 版本: ${MAILU_VERSION}  镜像前缀: ${org}"
        printf '%s\n' "# 修改后执行: cd ${MAILU_ROOT} && docker compose up -d"
        printf '%s\n' ""
        printf '%s\n' "services:"
        if [ "${MAILU_RESOLVER:-yes}" = "yes" ]; then
            printf '%s\n' "  # DNSSEC validating resolver (Mailu admin requires the AD bit)"
            printf '%s\n' "  resolver:"
            printf '    image: %s\n' "${org}/unbound:${MAILU_VERSION}"
            printf '%s\n' "    restart: always"
            printf '%s\n' "    env_file: mailu.env"
            printf '%s\n' "    dns:"
            printf '      - %s\n' "${MAILU_DNS_UPSTREAM:-9.9.9.9}"
            printf '%s\n' "    networks:"
            printf '%s\n' "      default:"
            printf '        ipv4_address: %s\n' "$resolver_ip"
            printf '%s\n' ""
        fi
        printf '%s\n' "  redis:"
        printf '    image: %s\n' "redis:alpine"
        printf '%s\n' "    restart: always"
        printf '%s\n' "    volumes:"
        printf '      - "%s/redis:/data"\n' "$MAILU_ROOT"
        printf '%s\n' ""
        printf '%s\n' "  front:"
        printf '    image: %s\n' "${org}/nginx:${MAILU_VERSION}"
        mc_dns_block
        printf '%s\n' "    restart: always"
        printf '%s\n' "    env_file: mailu.env"
        printf '%s\n' "    logging:"
        printf '%s\n' "      driver: journald"
        printf '%s\n' "      options:"
        printf '%s\n' "        tag: mailu-front"
        printf '%s\n' "    ports:"
        local p
        for p in 80 443 25 465 587 110 995 143 993 4190; do
            printf '      - "%s:%s:%s"\n' "${MAILU_BIND_ADDRESS4:-0.0.0.0}" "$p" "$p"
            if [ "$p" = "443" ]; then
                printf '      - "%s:%s:%s/udp"\n' "${MAILU_BIND_ADDRESS4:-0.0.0.0}" "$p" "$p"
            fi
        done
        printf '%s\n' "    networks:"
        printf '%s\n' "      - default"
        if [ "${MAILU_WEBMAIL:-none}" != "none" ]; then
            printf '%s\n' "      - webmail"
        fi
        if [ "${MAILU_WEBDAV:-none}" = "radicale" ]; then
            printf '%s\n' "      - radicale"
        fi
        printf '%s\n' "    volumes:"
        printf '      - "%s/certs:/certs"\n' "$MAILU_ROOT"
        printf '      - "%s/overrides/nginx:/overrides:ro"\n' "$MAILU_ROOT"
        printf '%s\n' "    depends_on:"
        printf '%s\n' "      - redis"
        printf '%s\n' ""
        printf '%s\n' "  imap:"
        printf '    image: %s\n' "${org}/dovecot:${MAILU_VERSION}"
        mc_dns_block
        printf '%s\n' "    restart: always"
        printf '%s\n' "    env_file: mailu.env"
        printf '%s\n' "    logging:"
        printf '%s\n' "      driver: journald"
        printf '%s\n' "      options:"
        printf '%s\n' "        tag: mailu-imap"
        printf '%s\n' "    volumes:"
        printf '      - "%s/mail:/mail"\n' "$MAILU_ROOT"
        printf '      - "%s/overrides/dovecot:/overrides:ro"\n' "$MAILU_ROOT"
        printf '%s\n' "    depends_on:"
        printf '%s\n' "      - front"
        printf '%s\n' ""
        printf '%s\n' "  smtp:"
        printf '    image: %s\n' "${org}/postfix:${MAILU_VERSION}"
        mc_dns_block
        printf '%s\n' "    restart: always"
        printf '%s\n' "    env_file: mailu.env"
        printf '%s\n' "    logging:"
        printf '%s\n' "      driver: journald"
        printf '%s\n' "      options:"
        printf '%s\n' "        tag: mailu-smtp"
        printf '%s\n' "    volumes:"
        printf '      - "%s/mailqueue:/queue"\n' "$MAILU_ROOT"
        printf '      - "%s/overrides/postfix:/overrides:ro"\n' "$MAILU_ROOT"
        printf '%s\n' "    depends_on:"
        printf '%s\n' "      - front"
        printf '%s\n' ""
        printf '%s\n' "  antispam:"
        printf '    image: %s\n' "${org}/rspamd:${MAILU_VERSION}"
        mc_dns_block
        printf '%s\n' "    restart: always"
        printf '%s\n' "    env_file: mailu.env"
        printf '%s\n' "    logging:"
        printf '%s\n' "      driver: journald"
        printf '%s\n' "      options:"
        printf '%s\n' "        tag: mailu-antispam"
        printf '%s\n' "    volumes:"
        printf '      - "%s/filter:/var/lib/rspamd"\n' "$MAILU_ROOT"
        printf '      - "%s/dkim:/dkim:ro"\n' "$MAILU_ROOT"
        printf '      - "%s/overrides/rspamd:/etc/rspamd/override.d:ro"\n' "$MAILU_ROOT"
        printf '%s\n' "    depends_on:"
        printf '%s\n' "      - front"
        printf '%s\n' ""
        printf '%s\n' "  admin:"
        printf '    image: %s\n' "${org}/admin:${MAILU_VERSION}"
        mc_dns_block
        printf '%s\n' "    restart: always"
        printf '%s\n' "    env_file: mailu.env"
        printf '%s\n' "    logging:"
        printf '%s\n' "      driver: journald"
        printf '%s\n' "      options:"
        printf '%s\n' "        tag: mailu-admin"
        printf '%s\n' "    volumes:"
        printf '      - "%s/data:/data"\n' "$MAILU_ROOT"
        printf '      - "%s/dkim:/dkim"\n' "$MAILU_ROOT"
        printf '%s\n' "    depends_on:"
        printf '%s\n' "      - redis"
        if [ "${MAILU_ANTIVIRUS:-none}" = "clamav" ]; then
            printf '%s\n' ""
            printf '%s\n' "  antivirus:"
            printf '    image: %s\n' "${org}/clamav:${MAILU_VERSION}"
            printf '%s\n' "    restart: always"
            printf '%s\n' "    env_file: mailu.env"
            printf '%s\n' "    volumes:"
            printf '      - "%s/filter:/data"\n' "$MAILU_ROOT"
            printf '%s\n' "    depends_on:"
            printf '%s\n' "      - front"
        fi
        if [ "${MAILU_WEBMAIL:-none}" != "none" ]; then
            printf '%s\n' ""
            printf '%s\n' "  webmail:"
            printf '    image: %s\n' "$webmail_image"
        mc_dns_block
            printf '%s\n' "    restart: always"
            printf '%s\n' "    env_file: mailu.env"
            printf '%s\n' "    volumes:"
            printf '      - "%s/webmail:/data"\n' "$MAILU_ROOT"
            printf '      - "%s/overrides/%s:/overrides:ro"\n' "$MAILU_ROOT" "$MAILU_WEBMAIL"
            printf '%s\n' "    networks:"
            printf '%s\n' "      - default"
            printf '%s\n' "      - webmail"
            printf '%s\n' "    depends_on:"
            printf '%s\n' "      - imap"
        fi
        if [ "${MAILU_WEBDAV:-none}" = "radicale" ]; then
            printf '%s\n' ""
            printf '%s\n' "  webdav:"
            printf '    image: %s\n' "${org}/radicale:${MAILU_VERSION}"
            printf '%s\n' "    restart: always"
            printf '%s\n' "    env_file: mailu.env"
            printf '%s\n' "    volumes:"
            printf '      - "%s/dav:/data"\n' "$MAILU_ROOT"
            printf '%s\n' "    networks:"
            printf '%s\n' "      - default"
            printf '%s\n' "      - radicale"
            printf '%s\n' "    depends_on:"
            printf '%s\n' "      - front"
        fi
        if [ "${MAILU_FETCHMAIL_ENABLED:-false}" = "true" ]; then
            printf '%s\n' ""
            printf '%s\n' "  fetchmail:"
            printf '    image: %s\n' "${org}/fetchmail:${MAILU_VERSION}"
            printf '%s\n' "    restart: always"
            printf '%s\n' "    env_file: mailu.env"
            printf '%s\n' "    depends_on:"
            printf '%s\n' "      - front"
        fi
        printf '%s\n' ""
        printf '%s\n' "networks:"
        printf '%s\n' "  default:"
        printf '%s\n' "    driver: bridge"
        printf '%s\n' "    ipam:"
        printf '%s\n' "      driver: default"
        printf '%s\n' "      config:"
        printf '        - subnet: %s\n' "${MAILU_SUBNET:-192.168.203.0/24}"
        if [ "${MAILU_WEBMAIL:-none}" != "none" ]; then
            printf '%s\n' "  webmail:"
            printf '%s\n' "    driver: bridge"
        fi
        if [ "${MAILU_WEBDAV:-none}" = "radicale" ]; then
            printf '%s\n' "  radicale:"
            printf '%s\n' "    driver: bridge"
        fi
    } >"$file"
    mc_ok "已生成 $file"
}

# ---------------------------------------------------------------------------
# Mailu 配置生成 / 容器启停 / API 初始化（mailu.sh 第 2 部分）
# ---------------------------------------------------------------------------

mc_write_env() {
    local file="$MAILU_ROOT/mailu.env"
    mc_step "生成 mailu.env"
    if [ "${MAILU_TLS_FLAVOR:-}" = "" ]; then
        MAILU_TLS_FLAVOR="letsencrypt"
    fi
    if mc_is_dry_run; then
        mc_plain "[dry-run] 写入 $file"
        return 0
    fi
    if [ -f "$file" ]; then
        cp -f "$file" "${file}.bak.$(date +%Y%m%d%H%M%S)"
        mc_info "已备份原有 mailu.env"
    fi
    # 限流值必须是 "50/hour" 这种格式，裸数字会让 limits.parse() 抛异常 -> 所有限流接口 500
    local rl_ip rl_user rl_ip_line rl_user_line
    rl_ip="${MAILU_AUTH_RATELIMIT_IP:-50}"
    rl_user="${MAILU_AUTH_RATELIMIT_USER:-100}"
    rl_ip="${rl_ip%%/*}"
    rl_user="${rl_user%%/*}"
    if [ "$rl_ip" = "0" ]; then
        rl_ip_line=""
    else
        rl_ip_line="AUTH_RATELIMIT_IP=${rl_ip}/hour"
    fi
    if [ "$rl_user" = "0" ]; then
        rl_user_line=""
    else
        rl_user_line="AUTH_RATELIMIT_USER=${rl_user}/day"
    fi

    local webroot="/webmail"
    if [ "${MAILU_WEBMAIL:-none}" = "none" ]; then
        if [ "${MAILU_ADMIN_GUI:-true}" = "true" ]; then
            webroot="/admin"
        else
            webroot=""
        fi
    fi
    cat >"$file" <<ENVFILE
# ---------------------------------------------------------------------------
# Mailu 主配置文件（由部署脚本生成 - $(date '+%Y-%m-%d %H:%M:%S')）
# 参考文档: https://mailu.io/configuration.html
# 修改后执行: cd $MAILU_ROOT && docker compose up -d
# ---------------------------------------------------------------------------

###################################
# 基础配置
###################################
SECRET_KEY=${MAILU_SECRET_KEY}
SUBNET=${MAILU_SUBNET}
DOMAIN=${MAILU_DOMAIN}
HOSTNAMES=${MAILU_HOSTNAMES}
POSTMASTER=${MAILU_POSTMASTER}
TLS_FLAVOR=${MAILU_TLS_FLAVOR}

${rl_ip_line}
${rl_user_line}
DISABLE_STATISTICS=${MAILU_DISABLE_STATISTICS}

###################################
# 功能开关
###################################
ADMIN=${MAILU_ADMIN_GUI}
WEBMAIL=${MAILU_WEBMAIL}
API=${MAILU_API}
WEBDAV=${MAILU_WEBDAV}
ANTIVIRUS=${MAILU_ANTIVIRUS}
SCAN_MACROS=false
FETCHMAIL_ENABLED=${MAILU_FETCHMAIL_ENABLED}

###################################
# 邮件参数
###################################
MESSAGE_SIZE_LIMIT=${MAILU_MESSAGE_SIZE_LIMIT}
MESSAGE_RATELIMIT=${MAILU_MESSAGE_RATELIMIT}
RELAYNETS=${MAILU_RELAYNETS}
RELAYHOST=${MAILU_RELAYHOST}
FETCHMAIL_DELAY=${MAILU_FETCHMAIL_DELAY}
RECIPIENT_DELIMITER=${MAILU_RECIPIENT_DELIMITER}
DMARC_RUA=${MAILU_DMARC_RUA}
DMARC_RUF=${MAILU_DMARC_RUF}
DMARC_SEND_REPORTS=false
WELCOME=${MAILU_WELCOME}
WELCOME_SUBJECT="欢迎使用企业邮箱"
WELCOME_BODY="您的邮箱已开通，请使用本邮件中的账号密码登录网页邮箱。"
COMPRESSION=${MAILU_COMPRESSION}
COMPRESSION_LEVEL=6
FULL_TEXT_SEARCH=en

###################################
# 网页配置
###################################
WEBROOT_REDIRECT=${webroot}
WEB_ADMIN=/admin
WEB_WEBMAIL=/webmail
WEB_API=/api
SITENAME="${MAILU_SITE_NAME}"
WEBSITE="${MAILU_WEBSITE}"

###################################
# 高级配置
###################################
COMPOSE_PROJECT_NAME=${MAILU_COMPOSE_PROJECT_NAME}
CREDENTIAL_ROUNDS=12
REAL_IP_HEADER=${MAILU_REAL_IP_HEADER}
REAL_IP_FROM=${MAILU_REAL_IP_FROM}
REJECT_UNLISTED_RECIPIENT=${MAILU_REJECT_UNLISTED_RECIPIENT}
LOG_LEVEL=INFO
TZ=${MAILU_TIMEZONE}
DEFAULT_SPAM_THRESHOLD=${MAILU_SPAM_THRESHOLD}

# 首次启动由 admin 容器自动创建全局管理员；mode=update 表示用户不存在则创建（新建即为全局管理员），已存在则只更新密码
INITIAL_ADMIN_ACCOUNT=${MAILU_ADMIN_USER}
INITIAL_ADMIN_DOMAIN=${MAILU_DOMAIN}
INITIAL_ADMIN_PW=${MAILU_ADMIN_PASS}
INITIAL_ADMIN_MODE=update

# REST API 访问令牌（中台/自动化系统使用，至少 3 个字符）
API_TOKEN=${MAILU_API_TOKEN}
FULL_TEXT_SEARCH_ATTACHMENTS=false
ENVFILE
    chmod 600 "$file" 2>/dev/null || true
    mc_ok "已生成 $file（含密钥，权限 600）"
}

mc_compose_pull() {
    mc_step "拉取 Mailu 容器镜像（首次较慢，请耐心等待）"
    if ! mc_compose "$MAILU_ROOT" pull; then
        mc_warn "镜像拉取出现失败（可能是镜像仓库网络受限），仍尝试启动"
    fi
}

mc_compose_up() {
    mc_step "启动 Mailu 容器"
    mc_compose "$MAILU_ROOT" up -d
}

mc_compose_ps() {
    mc_compose "$MAILU_ROOT" ps
}

mc_wait_containers() {
    local tries="${1:-40}" i=1 line
    mc_step "等待容器启动完成"
    if mc_is_dry_run; then
        return 0
    fi
    while [ "$i" -le "$tries" ]; do
        line="$(mc_compose "$MAILU_ROOT" ps 2>/dev/null | grep -c -E 'Up|running' || true)"
        if [ "${line:-0}" -ge 4 ]; then
            mc_ok "已检测到 ${line} 个容器运行中"
            return 0
        fi
        sleep 5
        i=$((i + 1))
    done
    mc_warn "等待容器超时，请检查: cd $MAILU_ROOT && docker compose logs --tail=80"
    return 1
}

# --- API 辅助 -----------------------------------------------------------
mc_api_url() {
    local host
    host="$(mc_primary_hostname)"
    printf 'https://%s/api/v1' "$host"
}

mc_api_call() {
    # 用法: mc_api_call METHOD PATH [JSON]
    local method="$1" path="$2" data="${3:-}"
    local url
    url="$(mc_api_url)${path}"
    if [ -n "$data" ]; then
        curl -sS -k -X "$method" -H "Authorization: Bearer ${MAILU_API_TOKEN}" \
            -H 'Content-Type: application/json' -d "$data" --max-time 30 "$url" 2>/dev/null
    else
        curl -sS -k -X "$method" -H "Authorization: Bearer ${MAILU_API_TOKEN}" \
            --max-time 30 "$url" 2>/dev/null
    fi
}

mc_api_wait() {
    local tries="${1:-30}" i=1
    mc_step "等待 Mailu API 就绪"
    if mc_is_dry_run; then
        return 0
    fi
    if ! mc_cmd_exists curl; then
        mc_warn "缺少 curl，跳过 API 初始化"
        return 1
    fi
    while [ "$i" -le "$tries" ]; do
        if mc_api_call GET /domain | grep -q '\['; then
            mc_ok "Mailu API 已就绪"
            return 0
        fi
        sleep 5
        i=$((i + 1))
    done
    mc_warn "Mailu API 未就绪（可能还在初始化或 API 未启用）"
    if mc_compose "$MAILU_ROOT" logs --tail=60 admin 2>/dev/null | grep -qi 'DNSSEC'; then
        mc_warn "检测到 admin 因 DNSSEC 校验失败而未启动，请执行上面的 dns override 修复"
    fi
    return 1
}

mc_json_value() {
    # 从 JSON 中取字符串值（优先 python3，回退 sed）
    local json="$1" key="$2"
    if mc_cmd_exists python3; then
        printf '%s' "$json" | python3 -c "import sys,json;d=json.load(sys.stdin);v=d.get('$key') if isinstance(d,dict) else None;print(v if v is not None else '')" 2>/dev/null && return 0
    fi
    printf '%s' "$json" | sed -n "s/.*\"${key}\"[[:space:]]*:[[:space:]]*\"\([^\"]*\)\".*/\1/p" | head -n 1
}

# 创建主域名 + 全局管理员账号（优先 API，失败回退 CLI）
mc_bootstrap_admin() {
    mc_step "初始化主域名与管理员账号"
    if mc_is_dry_run; then
        mc_plain "[dry-run] 将创建域名 ${MAILU_DOMAIN} 与管理员 ${MAILU_ADMIN_USER}@${MAILU_DOMAIN}，并生成 DKIM 密钥"
        return 0
    fi
    local api_ok="no"
    if mc_api_wait 24; then
        api_ok="yes"
    fi

    if [ "$api_ok" = "yes" ]; then
        local resp
        resp="$(mc_api_call POST /domain "{\"name\":\"${MAILU_DOMAIN}\",\"comment\":\"主域名 (由部署脚本创建)\"}")"
        case "$resp" in
            *'"code": 200'*|*'"code":200'*|*'has been created'*) mc_ok "域名 ${MAILU_DOMAIN} 已创建" ;;
            *Duplicate*|*'409'*) mc_info "域名 ${MAILU_DOMAIN} 已存在" ;;
            *) mc_warn "创建域名返回: $(printf '%s' "$resp" | head -c 200)" ;;
        esac
        resp="$(mc_api_call POST /user "{\"email\":\"${MAILU_ADMIN_USER}@${MAILU_DOMAIN}\",\"raw_password\":\"${MAILU_ADMIN_PASS}\",\"global_admin\":true,\"quota_bytes\":0,\"enabled\":true,\"displayed_name\":\"管理员\"}")"
        case "$resp" in
            *'"code": 200'*|*'"code":200'*|*'has been created'*) mc_ok "管理员 ${MAILU_ADMIN_USER}@${MAILU_DOMAIN} 已创建" ;;
            *already\ exists*|*Duplicate*|*'409'*) mc_info "管理员账号已存在" ;;
            *) mc_warn "创建管理员返回: $(printf '%s' "$resp" | head -c 200)" ;;
        esac
        mc_api_call POST "/domain/${MAILU_DOMAIN}/dkim" >/dev/null 2>&1 || mc_warn "DKIM 密钥生成失败，稍后可在中台里重试"
        mc_ensure_global_admin || true
        return 0
    fi

    mc_warn "改用容器内 CLI 创建管理员"
    if mc_is_dry_run; then
        return 0
    fi
    if ! mc_compose "$MAILU_ROOT" exec -T admin flask mailu domain "$MAILU_DOMAIN" >/dev/null 2>&1; then
        mc_warn "创建域名命令返回非零（可能已存在）"
    fi
    # 注意: 参数顺序是 <前缀> <域名> <密码>；mode=update 对已存在用户只改密码（不会提升为全局管理员），
    # 因此这里的 --mode update 仅在用户不存在时创建全局管理员，已存在的普通账号由 mc_ensure_global_admin 用 API 提升
    if mc_compose "$MAILU_ROOT" exec -T admin flask mailu admin "$MAILU_ADMIN_USER" "$MAILU_DOMAIN" "$MAILU_ADMIN_PASS" --mode update >/dev/null 2>&1; then
        mc_ok "已通过 CLI 创建/提升全局管理员 ${MAILU_ADMIN_USER}@${MAILU_DOMAIN}"
    else
        mc_warn "自动创建管理员失败，请手动执行:"
        mc_plain "  cd $MAILU_ROOT && docker compose exec admin flask mailu admin $MAILU_DOMAIN '你的密码'"
    fi
}

mc_fetch_dkim_records() {
    MC_DKIM_RECORD=""
    MC_DNS_MX=""
    MC_DNS_SPF=""
    MC_DNS_DMARC=""
    if [ "${MAILU_API:-true}" != "true" ] || mc_is_dry_run || ! mc_cmd_exists curl; then
        return 0
    fi
    local json
    json="$(mc_api_call GET "/domain/${MAILU_DOMAIN}")"
    [ -n "$json" ] || return 0
    MC_DNS_MX="$(mc_json_value "$json" dns_mx)"
    MC_DNS_SPF="$(mc_json_value "$json" dns_spf)"
    MC_DKIM_RECORD="$(mc_json_value "$json" dns_dkim)"
    MC_DNS_DMARC="$(mc_json_value "$json" dns_dmarc)"
    export MC_DKIM_RECORD MC_DNS_MX MC_DNS_SPF MC_DNS_DMARC
}

mc_status() {
    mc_detect_os
    mc_hr
    printf '%sMailu 运行状态%s\n' "$MC_C_BOLD" "$MC_C_RESET"
    mc_hr
    if [ ! -d "$MAILU_ROOT" ]; then
        mc_warn "未找到安装目录 $MAILU_ROOT"
        return 1
    fi
    if ! mc_docker_running; then
        mc_err "Docker 未运行"
        return 1
    fi
    mc_compose_ps
    mc_hr
    printf '访问地址: https://%s\n' "$(mc_primary_hostname)"
    printf '管理后台: https://%s/admin\n' "$(mc_primary_hostname)"
    printf '网页邮箱: https://%s/webmail\n' "$(mc_primary_hostname)"
    printf 'API 地址: https://%s/api/v1\n' "$(mc_primary_hostname)"
    printf '安装目录: %s\n' "$MAILU_ROOT"
    mc_hr
}

mc_backup() {
    local dir="${MAILU_BACKUP_DIR:-/var/backups/mailu}"
    local stamp
    stamp="$(date +%Y%m%d-%H%M%S)"
    local target="$dir/mailu-backup-$stamp.tar.gz"
    local keep="${MAILU_BACKUP_KEEP:-7}"
    mc_step "备份 Mailu 数据到 $target"
    if mc_is_dry_run; then
        mc_plain "[dry-run] tar 备份 $MAILU_ROOT"
        return 0
    fi
    mkdir -p "$dir"
    # 邮件正文/附件目录体积较大，默认不打包（可用 MAILU_BACKUP_MAIL=yes 包含）
    local extra=""
    if [ "${MAILU_BACKUP_MAIL:-no}" = "yes" ]; then
        extra="mail"
    fi
    # shellcheck disable=SC2086
    tar --exclude='./.cache' --exclude='./mailqueue' -czf "$target" \
        -C "$MAILU_ROOT" ./mailu.env ./docker-compose.yml ./data ./dkim ./certs ./redis ./webmail ./filter $extra 2>/dev/null || {
            mc_warn "部分目录打包失败，尝试最小备份"
            tar -czf "$target" -C "$MAILU_ROOT" ./mailu.env ./docker-compose.yml ./data ./dkim ./certs 2>/dev/null || mc_die "备份失败"
        }
    chmod 600 "$target" 2>/dev/null || true
    mc_ok "备份完成: $target ($(du -h "$target" 2>/dev/null | cut -f1))"
    ls -1t "$dir"/mailu-backup-*.tar.gz 2>/dev/null | tail -n +$((keep + 1)) | while IFS= read -r old; do
        rm -f "$old" && mc_info "已清理旧备份: $(basename "$old")"
    done
    printf '%s' "$target"
}

mc_restore() {
    local archive="$1"
    [ -f "$archive" ] || mc_die "备份文件不存在: $archive"
    mc_confirm "将从 $archive 恢复数据（会覆盖现有配置与数据库），确定继续？" || mc_die "已取消"
    mc_step "停止容器"
    mc_compose "$MAILU_ROOT" down
    mc_step "恢复备份"
    mc_run tar -xzf "$archive" -C "$MAILU_ROOT"
    mc_step "重新启动"
    mc_compose_up
    mc_ok "恢复完成"
}

mc_update() {
    mc_step "更新 Mailu（当前版本 ${MAILU_VERSION}）"
    mc_backup >/dev/null || mc_warn "更新前备份失败，请谨慎继续"
    if [ -n "${MAILU_NEW_VERSION:-}" ]; then
        MAILU_VERSION="$MAILU_NEW_VERSION"
        mc_info "目标版本: $MAILU_VERSION"
        mc_render_compose_and_env
    fi
    mc_compose_pull
    mc_compose_up
    mc_wait_containers
    mc_step "清理无用镜像"
    mc_compose "$MAILU_ROOT" down --remove-orphans >/dev/null 2>&1 || true
    mc_compose_up
    if mc_docker_running; then
        docker image prune -f >/dev/null 2>&1 || true
    fi
    mc_ok "更新完成"
    mc_status
}

mc_render_compose_and_env() {
    mc_write_compose
    mc_write_env
}

mc_uninstall() {
    local purge="${1:-no}"
    mc_confirm "将停止并删除 Mailu 容器（数据目录默认保留），确定继续？" || mc_die "已取消"
    mc_step "停止并删除容器"
    mc_compose "$MAILU_ROOT" down --remove-orphans || true
    if [ "$purge" = "yes" ]; then
        mc_confirm "危险操作：将删除数据目录 $MAILU_ROOT（邮件数据不可恢复），确定继续？" || mc_die "已取消（容器已停止）"
        mc_run rm -rf "$MAILU_ROOT"
        mc_info "如需删除镜像: docker image prune -a -f"
    fi
    mc_ok "卸载完成（数据目录: $MAILU_ROOT 保留=${MAILU_ROOT}）"
}

# --- 配置持久化 ---------------------------------------------------------
mc_save_config() {
    local dir="/etc/mailu-deploy"
    local file="$dir/mailu-deploy.conf"
    mc_info "保存部署参数到 $file（下次升级/备份脚本可直接读取）"
    if mc_is_dry_run; then
        return 0
    fi
    mkdir -p "$dir"
    {
        printf '%s\n' "# Mailu 部署参数（由安装脚本生成 $(date '+%Y-%m-%d %H:%M:%S')）"
        printf '%s\n' "# 用法: . $file && bash install.sh --upgrade --yes"
        local var
        for var in MAILU_DOMAIN MAILU_HOSTNAMES MAILU_TLS MAILU_ADMIN_USER MAILU_ADMIN_PASS MAILU_API_TOKEN \
            MAILU_VERSION MAILU_ROOT MAILU_SUBNET MAILU_SECRET_KEY MAILU_WEBMAIL MAILU_ADMIN_GUI MAILU_API \
            MAILU_ANTIVIRUS MAILU_WEBDAV MAILU_SITE_NAME MAILU_TIMEZONE MAILU_MIRROR_PREFIX MAILU_BIND_ADDRESS4 \
            MAILU_MESSAGE_SIZE_LIMIT MAILU_MESSAGE_RATELIMIT MAILU_CERTBOT_EMAIL MAILU_BACKUP_DIR MAILU_DEFAULT_QUOTA_MB \
            MAILU_DOCKER_ORG MAILU_FIREWALL_MANAGER MAILU_CONFIGURE_FIREWALL MAILU_INSTALL_DOCKER; do
            value="${!var:-}"
            printf '%s=%s\n' "$var" "'$value'"
        done
    } >"$file"
    chmod 600 "$file" 2>/dev/null || true
}

mc_load_saved_config() {
    local file="/etc/mailu-deploy/mailu-deploy.conf"
    if [ -f "$file" ]; then
        # shellcheck disable=SC1090
        . "$file"
        mc_info "已加载历史配置: $file"
    fi
}

# ---------------------------------------------------------------------------
# 安装后硬校验：admin 必须真的在 8080 上响应（否则 /admin 502、/webmail 500、API 不可用）
# ---------------------------------------------------------------------------
mc_verify_stack() {
    mc_step "校验核心服务是否可用"
    if mc_is_dry_run; then
        return 0
    fi
    local code
    code="$(mc_admin_probe)"
    if [ "$code" != "000" ]; then
        mc_ok "admin 已在 8080 响应（HTTP $code）"
        if [ "${MAILU_API:-true}" = "true" ]; then
            local api_body
            api_body="$(mc_api_call GET /domain 2>/dev/null)"
            case "$api_body" in
                \[*)
                    mc_ok "API 自检通过（GET /api/v1/domain 返回 JSON 数组）"
                    ;;
                *Internal\ Server\ Error*|*"500"*)
                    mc_warn "API 返回 500：常见原因是 AUTH_RATELIMIT_IP/AUTH_RATELIMIT_USER 缺少单位（应为 50/hour、100/day）"
                    mc_plain "  检查: grep -E '^AUTH_RATELIMIT' $MAILU_ROOT/mailu.env"
                    ;;
                *)
                    mc_warn "API 自检未返回预期结果（可能是 API 未启用或令牌不一致）"
                    ;;
            esac
        fi
        return 0
    fi

    mc_warn "admin 未在 8080 上响应：/admin 会 502、/webmail 会 500、API 不可用"
    if mc_compose "$MAILU_ROOT" logs --tail=60 admin 2>/dev/null | grep -qi 'DNSSEC'; then
        mc_warn "根因：DNS 未做 DNSSEC 校验。自动切换到公共校验型 DNS 并重建 admin/antispam"
        mc_write_dns_override "$(mc_dns_fallback1)" "$(mc_dns_fallback2)"
        mc_compose "$MAILU_ROOT" up -d --force-recreate admin antispam >/dev/null 2>&1 || true
        mc_compose "$MAILU_ROOT" restart front >/dev/null 2>&1 || true
        code="$(mc_admin_probe)"
        if [ "$code" != "000" ]; then
            mc_ok "自动修复成功：admin 已在 8080 响应（HTTP $code）"
            mc_info "已生成 $MAILU_ROOT/docker-compose.override.yml（如需恢复内置 unbound，删除该文件后重建 admin）"
            return 0
        fi
        mc_warn "自动修复后仍未就绪，请执行：cd $MAILU_ROOT && docker compose logs --tail=60 admin"
        return 1
    fi
    mc_compose "$MAILU_ROOT" logs --tail=20 admin 2>/dev/null | tail -n 20
    return 1
}

# 等待 admin 在 8080 上给出任何 HTTP 状态码（000 表示连不上）
mc_admin_probe() {
    local tries=24 i=1 code="000"
    while [ "$i" -le "$tries" ]; do
        code="$(mc_compose "$MAILU_ROOT" exec -T admin sh -c 'curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/internal/auth/user' 2>/dev/null | tr -dc '0-9')"
        [ -n "$code" ] || code="000"
        if [ "$code" != "000" ]; then
            printf '%s' "$code"
            return 0
        fi
        sleep 5
        i=$((i + 1))
    done
    printf '000'
}

mc_dns_fallback1() { printf '%s' "${MAILU_DNS_UPSTREAM:-9.9.9.9}"; }
mc_dns_fallback2() { printf '%s' "${MAILU_DNS_FALLBACK:-1.1.1.1}"; }

# 写 compose override：把 admin/antispam 的 DNS 指向可用且校验 DNSSEC 的上游
mc_write_dns_override() {
    local dns1="$1" dns2="$2"
    if mc_is_dry_run; then
        return 0
    fi
    {
        printf '%s\n' "# 由部署脚本自动生成：内置 unbound 未就绪时的兜底 DNS（DNSSEC 校验型）"
        printf '%s\n' "services:"
        printf '%s\n' "  admin:"
        printf '%s\n' "    dns:"
        printf '      - %s\n' "$dns1"
        printf '      - %s\n' "$dns2"
        printf '%s\n' "  antispam:"
        printf '%s\n' "    dns:"
        printf '      - %s\n' "$dns1"
        printf '      - %s\n' "$dns2"
    } >"$MAILU_ROOT/docker-compose.override.yml"
    mc_info "已写入 $MAILU_ROOT/docker-compose.override.yml"
}

# ---------------------------------------------------------------------------
# 校验管理员权限：不是 global_admin 就通过 API 提升（防止出现"能登录但 /admin 500"）
# ---------------------------------------------------------------------------
mc_ensure_global_admin() {
    local email="${MAILU_ADMIN_USER}@${MAILU_DOMAIN}"
    if mc_is_dry_run || [ "${MAILU_API:-true}" != "true" ]; then
        return 0
    fi
    local json
    json="$(mc_api_call GET "/user/${email}")"
    if [ -z "$json" ]; then
        return 0
    fi
    case "$json" in
        *'"global_admin": true'*|*'"global_admin":true'*)
            return 0
            ;;
    esac
    if mc_api_call PATCH "/user/${email}" '{"global_admin": true}' >/dev/null 2>&1; then
        mc_ok "已将 ${email} 提升为全局管理员"
    else
        mc_warn "${email} 不是全局管理员，自动提升失败。手工修复（任选其一）："
        mc_plain "  A) curl -sk -X PATCH -H \"Authorization: Bearer \$TOKEN\" -H 'Content-Type: application/json' -d '{\"global_admin\":true}' 'https://<主机名>/api/v1/user/${email//@/%40}'"
        mc_plain "  B) docker compose exec admin flask mailu user-delete ${email} -r && docker compose exec admin flask mailu admin ${MAILU_ADMIN_USER} ${MAILU_DOMAIN} '<密码>' --mode create"
    fi
}

# ---------------------------------------------------------------------------

# 合并自 deploy/lib/report.sh

# ---------------------------------------------------------------------------

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

# ---------------------------------------------------------------------------

# 合并自 deploy/install.sh（入口，最后执行 main）

# ---------------------------------------------------------------------------

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
