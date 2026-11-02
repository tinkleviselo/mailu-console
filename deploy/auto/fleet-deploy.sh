#!/usr/bin/env bash
# =============================================================================
#  Mailu 全局自动部署 - 舰队批量部署器 (fleet)
#
#  读取节点清单，对每台 VPS 并发执行引导安装（curl | bash 或 scp + bash），
#  每个节点单独日志，结束后输出汇总表；任一节点失败则退出码非 0。
#
#  用法:
#    bash fleet-deploy.sh --nodes fleet.nodes --parallel 4 --yes
#    bash fleet-deploy.sh --nodes fleet.nodes --dry-run      # 只打印将执行的 ssh 命令
#    bash fleet-deploy.sh --nodes fleet.nodes --only 1.2.3.4 # 只部署指定节点
#
#  节点清单 (每行: 目标 主域名 邮件主机名 [TLS模式] ["额外参数"]):
#    root@1.2.3.4:22   example.com   mail.example.com   letsencrypt
#    deploy@10.0.0.5   example.org   mail.example.org   cert  "--webmail roundcube"
#
#  依赖: 本机 ssh（建议免密钥登录目标机）、bash 4+
# =============================================================================
set -uo pipefail

BASE_URL="${MAILU_BASE_URL:-__MAILU_BASE_URL__}"
PKG_URL="${MAILU_PKG_URL:-}"
NODES_FILE="fleet.nodes"
PARALLEL=4
DRY_RUN="no"
ASSUME_YES="no"
TRANSPORT="curl"          # curl | scp
SSH_KEY=""
SSH_USER="root"
SSH_PORT="22"
ONLY=""
LOG_DIR="logs/fleet-$(date +%Y%m%d-%H%M%S)"
REPORT=""
HOSTKEY_OPTS=(-o StrictHostKeyChecking=accept-new)
BOOTSTRAP_LOCAL="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" && pwd)/bootstrap.sh"

if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
    C_R=$'\033[31m'; C_G=$'\033[32m'; C_Y=$'\033[33m'; C_B=$'\033[34m'; C_0=$'\033[0m'
else
    C_R=''; C_G=''; C_Y=''; C_B=''; C_0=''
fi
info() { printf '%s[信息]%s %s\n' "$C_B" "$C_0" "$*"; }
ok()   { printf '%s[成功]%s %s\n' "$C_G" "$C_0" "$*"; }
warn() { printf '%s[警告]%s %s\n' "$C_Y" "$C_0" "$*" >&2; }
err()  { printf '%s[错误]%s %s\n' "$C_R" "$C_0" "$*" >&2; }
die()  { err "$@"; exit 1; }

usage() { sed -n '4,22p' "$0" 2>/dev/null; }

while [ "$#" -gt 0 ]; do
    case "$1" in
        --nodes) shift; NODES_FILE="${1:-}" ;;
        --nodes=*) NODES_FILE="${1#*=}" ;;
        --parallel|-p) shift; PARALLEL="${1:-4}" ;;
        --parallel=*) PARALLEL="${1#*=}" ;;
        --base-url) shift; BASE_URL="${1:-}" ;;
        --base-url=*) BASE_URL="${1#*=}" ;;
        --pkg-url) shift; PKG_URL="${1:-}" ;;
        --pkg-url=*) PKG_URL="${1#*=}" ;;
        --ssh-key) shift; SSH_KEY="${1:-}" ;;
        --ssh-key=*) SSH_KEY="${1#*=}" ;;
        --user) shift; SSH_USER="${1:-root}" ;;
        --user=*) SSH_USER="${1#*=}" ;;
        --port) shift; SSH_PORT="${1:-22}" ;;
        --port=*) SSH_PORT="${1#*=}" ;;
        --only) shift; ONLY="${1:-}" ;;
        --only=*) ONLY="${1#*=}" ;;
        --log-dir) shift; LOG_DIR="${1:-}" ;;
        --log-dir=*) LOG_DIR="${1#*=}" ;;
        --report) shift; REPORT="${1:-}" ;;
        --report=*) REPORT="${1#*=}" ;;
        --transport) shift; TRANSPORT="${1:-curl}" ;;
        --transport=*) TRANSPORT="${1#*=}" ;;
        --no-host-key-check) HOSTKEY_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null) ;;
        --dry-run) DRY_RUN="yes" ;;
        --yes|-y) ASSUME_YES="yes" ;;
        -h|--help) usage; exit 0 ;;
        *) die "未知参数: $1（--help 查看用法）" ;;
    esac
    shift
done

[ -n "$PKG_URL" ] || case "$BASE_URL" in
    *__MAILU_BASE_URL__*) die "请先配置制品地址：--base-url https://cdn.example.com/mailu 或 --pkg-url <zip>" ;;
esac
[ -f "$NODES_FILE" ] || die "节点清单不存在: $NODES_FILE"
command -v ssh >/dev/null 2>&1 || die "本机缺少 ssh 命令"

SSH_OPTS=(-o BatchMode=yes -o ConnectTimeout=15 "${HOSTKEY_OPTS[@]}")
[ -n "$SSH_KEY" ] && SSH_OPTS+=(-i "$SSH_KEY")

mkdir -p "$LOG_DIR" 2>/dev/null || true
: >"$LOG_DIR/summary.txt"

remote_args() {
    # 组装传给 bootstrap 的参数（用 printf %q 安全转义）
    local domain="$1" hostname="$2" tls="$3" extra="$4"
    local args=""
    args="$args --domain $(printf '%q' "$domain")"
    args="$args --hostname $(printf '%q' "$hostname")"
    [ -n "$tls" ] && args="$args --tls $(printf '%q' "$tls")"
    if [ -n "$PKG_URL" ]; then
        args="$args --pkg-url $(printf '%q' "$PKG_URL")"
    else
        args="$args --base-url $(printf '%q' "$BASE_URL")"
    fi
    args="$args --yes"
    [ -n "$extra" ] && args="$args $extra"
    printf '%s' "$args"
}

deploy_one() {
    local target="$1" domain="$2" hostname="$3" tls="$4" extra="$5"
    local user="$SSH_USER" host="$target" port="$SSH_PORT"
    case "$target" in
        *@*) user="${target%%@*}"; host="${target#*@}" ;;
    esac
    case "$host" in
        *:*) port="${host##*:}"; host="${host%%:*}" ;;
    esac
    local log="$LOG_DIR/$host.log"
    local args
    args="$(remote_args "$domain" "$hostname" "$tls" "$extra")"

    if [ "$TRANSPORT" = "scp" ]; then
        # 上传引导脚本后在本机执行（适合制品不公开可访问的场景）
        local remote_script="/tmp/mailu-bootstrap-$$.sh"
        local cmd_scp=(scp "${SSH_OPTS[@]}" -P "$port" "$BOOTSTRAP_LOCAL" "$user@$host:$remote_script")
        local cmd_run=(ssh "${SSH_OPTS[@]}" -p "$port" "$user@$host" "sudo -n bash $remote_script $args; rc=\$?; rm -f $remote_script; exit \$rc")
    else
        local url="${PKG_URL:-${BASE_URL%/}/install.sh}"
        local remote_cmd="curl -fsSL $(printf '%q' "$url") | sudo -n bash -s -- $args"
        local cmd_scp=()
        local cmd_run=(ssh "${SSH_OPTS[@]}" -p "$port" "$user@$host" "$remote_cmd")
    fi

    if [ "$DRY_RUN" = "yes" ]; then
        printf '[dry-run] %s@%s:%s  domain=%s host=%s tls=%s\n' "$user" "$host" "$port" "$domain" "$hostname" "$tls"
        [ "${#cmd_scp[@]}" -gt 0 ] && printf '          %s\n' "${cmd_scp[*]}"
        printf '          %s\n' "${cmd_run[*]}"
        printf 'DRY-RUN  %s  %s\n' "$host" "ok" >>"$LOG_DIR/summary.txt"
        return 0
    fi

    info "开始部署 $user@$host:$port（$domain / $hostname，TLS=$tls）"
    {
        printf '===== %s  %s -> %s (%s) =====\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$user@$host:$port" "$domain" "$hostname"
    } >"$log"
    local rc=0
    if [ "${#cmd_scp[@]}" -gt 0 ]; then
        "${cmd_scp[@]}" >>"$log" 2>&1 || rc=$?
    fi
    if [ "$rc" -eq 0 ]; then
        "${cmd_run[@]}" >>"$log" 2>&1 || rc=$?
    fi
    if [ "$rc" -eq 0 ]; then
        ok "$host 部署完成"
        printf 'OK       %s  %s  %s\n' "$host" "$domain" "log=$log" >>"$LOG_DIR/summary.txt"
    else
        err "$host 部署失败（exit=$rc，日志: $log）"
        printf 'FAILED   %s  %s  exit=%s  log=%s\n' "$host" "$domain" "$rc" "$log" >>"$LOG_DIR/summary.txt"
    fi
    return "$rc"
}

# ---------------------------------------------------------------- 主流程
mapfile -t RAW < <(grep -v -E '^[[:space:]]*(#|$)' "$NODES_FILE" 2>/dev/null)
[ "${#RAW[@]}" -gt 0 ] || die "节点清单为空: $NODES_FILE"

TOTAL=0
PIDS=()
FAIL=0
START_TS=$(date +%s)

for line in "${RAW[@]}"; do
    # shellcheck disable=SC2086
    read -r target domain hostname tls extra <<<"$line"
    [ -n "${target:-}" ] || continue
    if [ -z "${domain:-}" ] || [ -z "${hostname:-}" ]; then
        warn "跳过格式不正确的行: $line"
        continue
    fi
    if [ -n "$ONLY" ] && [ "${target#*@}" != "$ONLY" ] && [ "$target" != "$ONLY" ]; then
        continue
    fi
    # shellcheck disable=SC2086
    TOTAL=$((TOTAL + 1))
    deploy_one "$target" "$domain" "$hostname" "${tls:-}" "${extra:-}" &
    PIDS+=($!)
    # 并发上限
    while [ "$(jobs -rp | wc -l)" -ge "$PARALLEL" ]; do
        sleep 1
    done
done

if [ "$DRY_RUN" != "yes" ]; then
    for pid in "${PIDS[@]}"; do
        if ! wait "$pid"; then
            FAIL=$((FAIL + 1))
        fi
    done
else
    wait
fi

DUR=$(( $(date +%s) - START_TS ))
echo
info "===== 部署汇总（共 $TOTAL 个节点，耗时 ${DUR}s）====="
if [ -s "$LOG_DIR/summary.txt" ]; then
    sed 's/^/  /' "$LOG_DIR/summary.txt"
fi
if [ -n "$REPORT" ]; then
    {
        printf 'host,domain,result,log\n'
        awk '{printf "%s,%s,%s,%s\n", $2, $3, ($1=="OK"?"ok":"failed"), $NF}' "$LOG_DIR/summary.txt"
    } >"$REPORT"
    info "汇总报告: $REPORT"
fi
info "节点日志目录: $LOG_DIR"

if [ "$FAIL" -gt 0 ] || grep -q '^FAILED' "$LOG_DIR/summary.txt" 2>/dev/null; then
    err "有节点部署失败，请检查对应日志"
    exit 1
fi
ok "全部节点部署成功"