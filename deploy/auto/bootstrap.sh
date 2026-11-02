#!/usr/bin/env bash
# =============================================================================
#  Mailu 全局自动部署 - 引导安装器 (bootstrap)
#
#  一条命令：下载安装包 -> SHA256 校验 -> 解压到版本目录 -> 调用 deploy/install.sh
#  压缩包放在任意 CDN / 对象存储即可，只需改下面的 MAILU_BASE_URL（或运行时覆盖）。
#
#  用法：
#    curl -fsSL https://your-cdn.example.com/mailu/install.sh | bash
#    curl -fsSL https://your-cdn.example.com/mailu/install.sh | bash -s -- \
#         --domain example.com --hostname mail.example.com
#    bash install.sh --version 1.0.0 --domain a.com --hostname mail.a.com --yes
#    bash install.sh --pkg-url https://x.com/mailu-platform-1.0.0.zip --yes
#    bash install.sh --upgrade     # 更新脚本包并更新 Mailu 镜像
#    bash install.sh --rollback    # 回滚到上一个脚本包版本
#    bash install.sh --list        # 列出已安装的脚本包版本
#
#  环境变量：MAILU_BASE_URL MAILU_PKG_URL MAILU_PKG_SHA256 MAILU_INSTALL_DIR
#            MAILU_HTTP_PROXY MAILU_INSECURE=1 MAILU_NO_VERIFY=1
# =============================================================================
set -uo pipefail

if [ -z "${BASH_VERSION:-}" ]; then
    echo "请使用 bash 运行：curl -fsSL <url>/install.sh | bash" >&2
    exit 1
fi

# ---- 制品根地址：发布时由 build/release.py 注入，也可运行时覆盖 ----
MAILU_BASE_URL="${MAILU_BASE_URL:-__MAILU_BASE_URL__}"
MAILU_INSTALL_DIR="${MAILU_INSTALL_DIR:-/opt/mailu-deploy}"
MAILU_PKG_URL="${MAILU_PKG_URL:-}"
MAILU_PKG_SHA256="${MAILU_PKG_SHA256:-}"
MAILU_INSECURE="${MAILU_INSECURE:-0}"
MAILU_DRY_RUN="${MAILU_DRY_RUN:-no}"
MAILU_ASSUME_YES="${MAILU_ASSUME_YES:-no}"
MAILU_ACTION="install"
MAILU_VERSION_ARG=""
MAILU_KEEP_TEMP="no"
MAILU_EXTRACT_ONLY="${MAILU_EXTRACT_ONLY:-0}"
RESOLVED_URL=""; RESOLVED_SHA=""; RESOLVED_VERSION=""; PKG_FILE=""; PKG_KIND="zip"

if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
    C_R=$'\033[31m'; C_G=$'\033[32m'; C_Y=$'\033[33m'; C_B=$'\033[34m'; C_BOLD=$'\033[1m'; C_0=$'\033[0m'
else
    C_R=''; C_G=''; C_Y=''; C_B=''; C_BOLD=''; C_0=''
fi

info() { printf '%s[信息]%s %s\n' "$C_B" "$C_0" "$*"; }
ok()   { printf '%s[成功]%s %s\n' "$C_G" "$C_0" "$*"; }
warn() { printf '%s[警告]%s %s\n' "$C_Y" "$C_0" "$*" >&2; }
err()  { printf '%s[错误]%s %s\n' "$C_R" "$C_0" "$*" >&2; }
die()  { err "$@"; exit 1; }
step() { printf '\n%s==>%s %s%s%s\n' "$C_B" "$C_0" "$C_BOLD" "$*" "$C_0"; }

usage() {
    cat <<'USAGE'
Mailu 全局自动部署引导安装器

用法: bash install.sh [引导参数] [安装参数...]

引导参数:
  --base-url URL       制品根地址（含 latest.json），默认取脚本内置值
  --pkg-url URL        直接指定安装包地址（zip/tar.gz/单文件 sh）
  --pkg-sha256 HASH    安装包 SHA256（不填则读 latest.json 或 .sha256）
  --version X          指定脚本包版本（默认读取 latest.json 的 version）
  --install-dir DIR    脚本包安装目录（默认 /opt/mailu-deploy）
  --upgrade            更新脚本包并执行 Mailu 镜像更新
  --rollback           回滚到上一个脚本包版本
  --list               列出已安装版本
  --insecure           跳过 HTTPS 证书校验（自签证书内网环境）
  --extract-only       只下载/校验/解压脚本包，不执行安装（预置/灰度用）
  --no-verify          跳过 SHA256 校验（不推荐）
  --keep-temp          保留下载临时文件
  --dry-run            只展示将执行的步骤
  --yes, -y            非交互确认
  -h, --help           显示帮助

安装参数（原样转发给 deploy/install.sh）:
  --domain --hostname --tls --admin-user --admin-pass --api-token --webmail
  --mirror-prefix --docker-org --root --subnet --no-docker --no-firewall
  --antivirus --email --site-name --timezone --quota
USAGE
}

is_dry_run() { [ "$MAILU_DRY_RUN" = "yes" ]; }
has() { command -v "$1" >/dev/null 2>&1; }

root_guard() {
    local uid=""
    has id && uid="$(id -u 2>/dev/null || echo '')"
    if [ "$uid" = "0" ] || is_dry_run || [ "${MAILU_ALLOW_NONROOT:-no}" = "yes" ]; then
        return 0
    fi
    if has sudo; then
        warn "非 root，尝试使用 sudo 重新执行"
        exec sudo -E bash "$0" "$@"
    fi
    die "请使用 root 运行（sudo bash install.sh ...）"
}

ensure_tools() {
    local missing="" c
    for c in curl tar; do has "$c" || missing="$missing $c"; done
    if has curl; then :; elif has wget; then :; else missing="$missing curl"; fi
    if [ -z "$missing" ]; then return 0; fi
    warn "缺少工具:$missing，尝试安装"
    if has apt-get; then
        DEBIAN_FRONTEND=noninteractive apt-get update -qq >/dev/null 2>&1
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq $missing ca-certificates >/dev/null 2>&1
    elif has dnf; then dnf install -y -q $missing >/dev/null 2>&1
    elif has yum; then yum install -y -q $missing >/dev/null 2>&1
    elif has zypper; then zypper --non-interactive -q install $missing >/dev/null 2>&1
    elif has apk; then apk add --no-cache -q $missing >/dev/null 2>&1
    elif has pacman; then pacman -S --noconfirm --needed --quiet $missing >/dev/null 2>&1
    fi
    for c in $missing; do has "$c" || die "缺少必要命令: $c"; done
    if ! has unzip && ! has python3; then
        if has apt-get; then DEBIAN_FRONTEND=noninteractive apt-get install -y -qq unzip >/dev/null 2>&1; fi
        if has dnf; then dnf install -y -q unzip >/dev/null 2>&1; fi
    fi
}

fetch() {
    local url="$1" dest="$2" i=0
    [ -n "$url" ] || return 1
    mkdir -p "$(dirname "$dest")" 2>/dev/null || true
    local opts=()
    [ "$MAILU_INSECURE" = "1" ] && opts+=("--insecure")
    [ -n "${MAILU_HTTP_PROXY:-}" ] && opts+=("--proxy" "$MAILU_HTTP_PROXY")
    while [ "$i" -lt 3 ]; do
        i=$((i + 1))
        if is_dry_run; then
            printf '[dry-run] 下载 %s -> %s\n' "$url" "$dest"
            : >"$dest"
            return 0
        fi
        if has curl; then
            if curl -fsSL --connect-timeout 15 --retry 2 --max-time 900 \
                --proto '=https,http' ${opts[@]+"${opts[@]}"} -o "$dest.part" "$url"; then
                mv -f "$dest.part" "$dest"
                return 0
            fi
        elif has wget; then
            if wget -q --timeout=30 --tries=2 -O "$dest.part" "$url"; then
                mv -f "$dest.part" "$dest"
                return 0
            fi
        else
            die "缺少 curl 或 wget"
        fi
        warn "下载失败（第 $i 次）: $url"
        sleep 2
    done
    rm -f "$dest.part" 2>/dev/null || true
    return 1
}

sha256_of() {
    local file="$1"
    if has sha256sum; then sha256sum "$file" | awk '{print $1}'
    elif has shasum; then shasum -a 256 "$file" | awk '{print $1}'
    elif has openssl; then openssl dgst -sha256 "$file" | awk '{print $NF}'
    elif has python3; then python3 - "$file" <<'PY'
import hashlib, sys
h = hashlib.sha256()
with open(sys.argv[1], 'rb') as fh:
    for chunk in iter(lambda: fh.read(65536), b''):
        h.update(chunk)
print(h.hexdigest())
PY
    else printf ''
    fi
}

json_field() {
    local file="$1" key="$2" out=""
    if has python3; then
        out="$(python3 - "$file" "$key" 2>/dev/null <<'PY'
import json, sys
try:
    data = json.load(open(sys.argv[1], encoding='utf-8'))
except Exception:
    sys.exit(1)
node = data
for part in sys.argv[2].split('.'):
    if isinstance(node, dict) and part in node:
        node = node[part]
    else:
        print('')
        sys.exit(0)
print(node if isinstance(node, (str, int, float)) else '')
PY
)"
        if [ -n "$out" ]; then
            printf '%s' "$out"
            return 0
        fi
    fi
    sed -n "s/.*\"${key##*.}\"[[:space:]]*:[[:space:]]*\"\([^\"]*\)\".*/\1/p" "$file" | head -n 1
}

parse_args() {
    while [ "$#" -gt 0 ]; do
        case "$1" in
            --base-url) shift; MAILU_BASE_URL="${1:-}" ;;
            --base-url=*) MAILU_BASE_URL="${1#*=}" ;;
            --pkg-url) shift; MAILU_PKG_URL="${1:-}" ;;
            --pkg-url=*) MAILU_PKG_URL="${1#*=}" ;;
            --pkg-sha256) shift; MAILU_PKG_SHA256="${1:-}" ;;
            --pkg-sha256=*) MAILU_PKG_SHA256="${1#*=}" ;;
            --install-dir) shift; MAILU_INSTALL_DIR="${1:-}" ;;
            --install-dir=*) MAILU_INSTALL_DIR="${1#*=}" ;;
            --version) shift; MAILU_VERSION_ARG="${1:-}" ;;
            --version=*) MAILU_VERSION_ARG="${1#*=}" ;;
            --insecure) MAILU_INSECURE="1" ;;
            --no-verify) MAILU_NO_VERIFY="1" ;;
            --keep-temp) MAILU_KEEP_TEMP="yes" ;;
            --upgrade) MAILU_ACTION="upgrade" ;;
            --rollback) MAILU_ACTION="rollback" ;;
            --list|--list-versions) MAILU_ACTION="list" ;;
            --extract-only) MAILU_EXTRACT_ONLY="1" ;;
            --dry-run) MAILU_DRY_RUN="yes"; FORWARD+=("--dry-run") ;;
            --yes|-y|--non-interactive) MAILU_ASSUME_YES="yes"; FORWARD+=("--yes") ;;
            -h|--help) usage; exit 0 ;;
            *) FORWARD+=("$1") ;;
        esac
        shift
    done
}
FORWARD=()

detect_kind() {
    case "$1" in
        *.zip) echo zip ;;
        *.tar.gz|*.tgz) echo tar ;;
        *.sh) echo script ;;
        *) echo zip ;;
    esac
}

resolve_pkg() {
    local stage="$1"
    if [ -n "$MAILU_PKG_URL" ]; then
        RESOLVED_URL="$MAILU_PKG_URL"
        RESOLVED_SHA="$MAILU_PKG_SHA256"
        RESOLVED_VERSION="${MAILU_VERSION_ARG:-}"
        return 0
    fi
    case "$MAILU_BASE_URL" in
        *"__MAILU"*"BASE_URL__"*|"")
            die "尚未配置制品地址。请任选一种方式：
  1) 发布时注入：python3 build/release.py --base-url https://cdn.example.com/mailu
  2) 运行时指定：MAILU_BASE_URL=https://cdn.example.com/mailu bash install.sh ...
  3) 直接给包地址：bash install.sh --pkg-url https://cdn.example.com/mailu/mailu-platform-1.0.0.zip"
            ;;
    esac

    if [ -n "$MAILU_VERSION_ARG" ]; then
        RESOLVED_URL="${MAILU_BASE_URL%/}/mailu-platform-${MAILU_VERSION_ARG}.zip"
        RESOLVED_SHA="$MAILU_PKG_SHA256"
        RESOLVED_VERSION="$MAILU_VERSION_ARG"
        if [ -z "$RESOLVED_SHA" ]; then
            if fetch "${RESOLVED_URL}.sha256" "$stage/version.sha256" 2>/dev/null; then
                RESOLVED_SHA="$(awk '{print $1}' "$stage/version.sha256" | head -n 1)"
            fi
        fi
        return 0
    fi

    step "读取发布清单 latest.json"
    local manifest="$stage/latest.json"
    fetch "${MAILU_BASE_URL%/}/latest.json" "$manifest" || die "无法获取发布清单: ${MAILU_BASE_URL%/}/latest.json"
    RESOLVED_VERSION="$(json_field "$manifest" version)"
    RESOLVED_URL="$(json_field "$manifest" files.platform.url)"
    RESOLVED_SHA="$(json_field "$manifest" files.platform.sha256)"
    if [ -z "$RESOLVED_URL" ] && is_dry_run; then
        RESOLVED_VERSION="${RESOLVED_VERSION:-latest}"
        RESOLVED_URL="${MAILU_BASE_URL%/}/mailu-platform-${RESOLVED_VERSION}.zip"
        warn "dry-run：清单未真正下载，按约定地址演示: $RESOLVED_URL"
    fi
    [ -n "$RESOLVED_URL" ] || die "发布清单缺少 files.platform.url"
    [ -z "$MAILU_PKG_SHA256" ] || RESOLVED_SHA="$MAILU_PKG_SHA256"
    info "清单版本: ${RESOLVED_VERSION:-未知}  包地址: $RESOLVED_URL"
}

download_package() {
    local stage="$1"
    PKG_KIND="$(detect_kind "$RESOLVED_URL")"
    PKG_FILE="$stage/pkg.$( [ "$PKG_KIND" = "tar" ] && echo tar.gz || echo zip )"
    step "下载安装包"
    fetch "$RESOLVED_URL" "$PKG_FILE" || die "安装包下载失败: $RESOLVED_URL"
    if is_dry_run; then
        ok "[dry-run] 跳过校验与解压"
        return 0
    fi
    local size
    size="$(wc -c <"$PKG_FILE" 2>/dev/null | tr -d ' ')"
    ok "已下载 $(basename "$PKG_FILE")（$(awk -v s="${size:-0}" 'BEGIN{printf "%.1f", s/1024}') KB）"

    if [ -z "$RESOLVED_SHA" ] && [ "${MAILU_NO_VERIFY:-0}" != "1" ]; then
        if fetch "${RESOLVED_URL}.sha256" "$stage/pkg.sha256" 2>/dev/null; then
            RESOLVED_SHA="$(awk '{print $1}' "$stage/pkg.sha256" 2>/dev/null | head -n 1)"
            [ -n "$RESOLVED_SHA" ] && info "使用随包校验文件 .sha256"
        fi
    fi
    if [ "${MAILU_NO_VERIFY:-0}" = "1" ]; then
        warn "已按参数跳过 SHA256 校验"
        return 0
    fi
    if [ -z "$RESOLVED_SHA" ]; then
        warn "未提供 SHA256，跳过完整性校验（建议让发布清单固定 sha256）"
        return 0
    fi
    local actual
    actual="$(sha256_of "$PKG_FILE")"
    if [ -z "$actual" ]; then
        warn "本机缺少 sha256 工具，跳过校验"
    elif [ "$actual" != "$RESOLVED_SHA" ]; then
        die "SHA256 校验失败，文件可能被篡改或下载不完整
  期望: $RESOLVED_SHA
  实际: $actual"
    else
        ok "SHA256 校验通过"
    fi
}

extract_package() {
    local file="$1" kind="$2" dest="$3"
    mkdir -p "$dest"
    case "$kind" in
        zip)
            if has unzip; then unzip -q -o "$file" -d "$dest"
            elif has python3; then python3 -m zipfile -e "$file" "$dest"
            elif has bsdtar; then bsdtar -xf "$file" -C "$dest"
            elif tar -tf "$file" >/dev/null 2>&1; then tar -xf "$file" -C "$dest"
            else die "无法解压 ZIP：请安装 unzip（apt install unzip）"
            fi
            ;;
        tar) tar -xzf "$file" -C "$dest" ;;
        script)
            cp -f "$file" "$dest/mailu-deploy.bundle.sh"
            chmod +x "$dest/mailu-deploy.bundle.sh"
            ;;
    esac
}

find_installer() {
    local root="$1" p
    for p in \
        "$root/deploy/install.sh" \
        "$root/mailu-deploy/install.sh" \
        "$root"/mailu-platform-*/deploy/install.sh \
        "$root"/mailu-deploy*/deploy/install.sh \
        "$root"/mailu-*/mailu-deploy/install.sh \
        "$root"/mailu-*/mailu-deploy.bundle.sh \
        "$root/mailu-deploy/mailu-deploy.bundle.sh" \
        "$root/mailu-deploy.bundle.sh"; do
        if [ -f "$p" ]; then printf '%s' "$p"; return 0; fi
    done
    return 1
}

list_releases() { ls -1 "$MAILU_INSTALL_DIR/releases" 2>/dev/null | sort; }

current_version() {
    local link="$MAILU_INSTALL_DIR/current" target="" marker="$MAILU_INSTALL_DIR/.current"
    if [ -f "$marker" ]; then
        target="$(head -n 1 "$marker" 2>/dev/null || true)"
        [ -n "$target" ] && { printf '%s' "$target"; return 0; }
    fi
    [ -e "$link" ] || [ -L "$link" ] || { printf ''; return 0; }
    if [ -L "$link" ]; then
        target="$(readlink "$link" 2>/dev/null || true)"
        if [ -z "$target" ]; then target="$(readlink -f "$link" 2>/dev/null || true)"; fi
    else
        target="$link"
    fi
    [ -n "$target" ] || { printf ''; return 0; }
    basename "$target"
}

link_current() {
    local target="$1" link="$MAILU_INSTALL_DIR/current"
    mkdir -p "$MAILU_INSTALL_DIR"
    rm -rf "$link" 2>/dev/null || true
    ln -s "$target" "$link" 2>/dev/null || cp -a "$target" "$link"
    printf '%s\n' "$(basename "$target")" >"$MAILU_INSTALL_DIR/.current" 2>/dev/null || true
}

action_list() {
    step "已安装的脚本包版本（$MAILU_INSTALL_DIR）"
    if [ -z "$(list_releases)" ]; then
        warn "还没有安装任何版本"
        return 0
    fi
    local cur
    cur="$(current_version)"
    list_releases | while IFS= read -r v; do
        if [ "$v" = "$cur" ]; then printf '  * %s (current)\n' "$v"; else printf '    %s\n' "$v"; fi
    done
    [ -f "$MAILU_INSTALL_DIR/.state" ] && { echo; info "最近一次安装记录:"; sed 's/^/    /' "$MAILU_INSTALL_DIR/.state"; }
}

action_install() {
    local stage="$MAILU_INSTALL_DIR/.tmp/$(date +%s)"
    mkdir -p "$stage"
    resolve_pkg "$stage"
    download_package "$stage"

    local version="${RESOLVED_VERSION:-}"
    if [ -z "$version" ]; then
        version="$(date +%Y%m%d%H%M%S)"
        warn "未能确定版本号，使用时间戳: $version"
    fi
    local rel="$MAILU_INSTALL_DIR/releases/$version"

    if is_dry_run; then
        step "[dry-run] 后续步骤"
        printf '  解压到 : %s\n' "$rel"
        printf '  软链接 : %s/current -> %s\n' "$MAILU_INSTALL_DIR" "$rel"
        printf '  执行   : bash %s/deploy/install.sh %s\n' "$rel" "${FORWARD[*]:-}"
        [ -n "$MAILU_KEEP_TEMP" ] && rm -rf "$stage" 2>/dev/null
        return 0
    fi

    step "解压到 $rel"
    rm -rf "$rel"
    mkdir -p "$rel"
    extract_package "$PKG_FILE" "$PKG_KIND" "$rel"
    link_current "$rel"

    local installer
    installer="$(find_installer "$rel")" || die "安装包中找不到 deploy/install.sh（请确认上传的是 platform 或 deploy 包）"
    ok "安装器: $installer"

    {
        printf 'version=%s\n' "$version"
        printf 'source=%s\n' "$RESOLVED_URL"
        printf 'sha256=%s\n' "${RESOLVED_SHA:-未校验}"
        printf 'installed_at=%s\n' "$(date '+%Y-%m-%d %H:%M:%S')"
    } >"$MAILU_INSTALL_DIR/.state"
    chmod 600 "$MAILU_INSTALL_DIR/.state" 2>/dev/null || true

    if [ "$MAILU_KEEP_TEMP" != "yes" ]; then
        rm -f "$PKG_FILE" "$stage"/*.sha256 2>/dev/null || true
        [ -d "$stage" ] && rm -rf "$stage" 2>/dev/null || true
    fi

    if [ "$MAILU_EXTRACT_ONLY" = "1" ]; then
        step "仅解压模式（--extract-only），跳过安装"
        ok "脚本包已就绪: $rel"
        info "下一步可执行: bash $installer --domain example.com --hostname mail.example.com --yes"
        return 0
    fi

    step "执行安装（$(basename "$installer")）"
    if [ "$(basename "$installer")" = "install.sh" ]; then
        bash "$installer" ${FORWARD[@]+"${FORWARD[@]}"}
    else
        bash "$installer" ${FORWARD[@]+"${FORWARD[@]}"}
    fi
    local rc=$?
    echo
    if [ "$rc" -eq 0 ]; then
        ok "自动部署完成（脚本包版本 $version）"
        info "后续运维：bash $MAILU_INSTALL_DIR/current/deploy/bin/mailu-ctl status"
    else
        err "安装器返回失败码 $rc，请查看上面的输出与 /var/log/mailu-deploy.log"
    fi
    return "$rc"
}

action_upgrade() {
    step "升级脚本包并更新 Mailu"
    [ -d "$MAILU_INSTALL_DIR/current" ] || die "尚未安装，请先执行一次全新部署"
    local prev
    prev="$(current_version)"
    # 先备份数据
    local installer
    installer="$(find_installer "$MAILU_INSTALL_DIR/current")" || true
    if [ -n "$installer" ] && is_dry_run; then
        printf '[dry-run] 备份数据: bash %s --action backup\n' "$installer"
    elif [ -n "$installer" ]; then
        info "升级前备份数据"
        bash "$installer" --action backup || warn "备份失败，建议先手动备份再升级"
    fi
    # 下载新版本（保持原有安装参数）
    local stage="$MAILU_INSTALL_DIR/.tmp/upgrade-$(date +%s)"
    mkdir -p "$stage"
    resolve_pkg "$stage"
    download_package "$stage"
    local version="${RESOLVED_VERSION:-$(date +%Y%m%d%H%M%S)}"
    local rel="$MAILU_INSTALL_DIR/releases/$version"
    if is_dry_run; then
        printf '[dry-run] 新版本将解压到 %s 并执行 --action update\n' "$rel"
        return 0
    fi
    rm -rf "$rel"; mkdir -p "$rel"
    extract_package "$PKG_FILE" "$PKG_KIND" "$rel"
    link_current "$rel"
    local new_installer
    new_installer="$(find_installer "$rel")" || die "新版本安装包不完整"
    step "执行更新（deploy/install.sh --action update）"
    bash "$new_installer" --action update --yes
    local rc=$?
    if [ "$rc" -eq 0 ]; then
        ok "升级完成（$prev -> $version）"
    else
        err "升级失败（返回码 $rc），可回滚: bash $0 --rollback"
    fi
    return "$rc"
}

action_rollback() {
    step "回滚脚本包版本"
    local cur target
    cur="$(current_version)"
    target="$(list_releases | grep -v -x "$cur" | tail -n 1)"
    [ -n "$target" ] || die "没有可回滚的历史版本"
    info "当前: ${cur:-未知}  目标: $target"
    if is_dry_run; then
        printf '[dry-run] 切换 current -> releases/%s 并执行 --action reconfigure\n' "$target"
        return 0
    fi
    link_current "$MAILU_INSTALL_DIR/releases/$target"
    local installer
    installer="$(find_installer "$MAILU_INSTALL_DIR/current")" || die "目标版本不完整"
    warn "脚本包已切换到 $target；如 Mailu 镜像也需回滚，请执行: bash $installer --action update --new-version <旧版本> --yes"
    ok "回滚完成"
}

main() {
    parse_args "$@"
    case "$MAILU_ACTION" in
        list) action_list; exit 0 ;;
        help) usage; exit 0 ;;
    esac
    step "Mailu 全局自动部署引导器"
    info "制品地址: ${MAILU_PKG_URL:-$MAILU_BASE_URL}"
    info "安装目录: $MAILU_INSTALL_DIR"
    is_dry_run && warn "dry-run 模式：不会下载/解压/安装"
    root_guard "$@"
    ensure_tools
    case "$MAILU_ACTION" in
        install) action_install ;;
        upgrade) action_upgrade ;;
        rollback) action_rollback ;;
        *) die "未知动作: $MAILU_ACTION" ;;
    esac
}

main "$@"