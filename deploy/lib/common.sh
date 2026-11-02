#!/usr/bin/env bash
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