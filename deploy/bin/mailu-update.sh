#!/usr/bin/env bash
# 更新 Mailu 容器镜像（更新前会自动备份）
set -uo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" && pwd)"
BASE="$(cd "$DIR/.." && pwd)"
# shellcheck source=../lib/common.sh
. "$BASE/lib/common.sh"
for lib in docker firewall tls mailu report; do
    [ -f "$BASE/lib/${lib}.sh" ] && . "$BASE/lib/${lib}.sh"
done
: "${MAILU_ROOT:=/mailu}"
: "${MAILU_ASSUME_YES:=yes}"
mc_load_saved_config >/dev/null 2>&1 || true
while [ "$#" -gt 0 ]; do
    case "$1" in
        --new-version) shift; MAILU_NEW_VERSION="${1:-}" ;;
        --no-backup) MAILU_SKIP_BACKUP="yes" ;;
        --yes|-y) MAILU_ASSUME_YES="yes" ;;
        *) mc_warn "忽略未知参数: $1" ;;
    esac
    shift
done
mc_detect_os
mc_ensure_base_tools
mc_require_root
if [ "${MAILU_SKIP_BACKUP:-no}" != "yes" ]; then
    mc_backup >/dev/null && mc_ok "更新前备份完成"
fi
if [ -n "${MAILU_NEW_VERSION:-}" ]; then
    mc_info "目标版本: ${MAILU_NEW_VERSION}"
    MAILU_VERSION="$MAILU_NEW_VERSION"
    mc_write_compose
    mc_write_env
fi
mc_compose_pull
mc_compose_up
mc_wait_containers 40 || true
mc_save_config
mc_ok "更新完成"
mc_status