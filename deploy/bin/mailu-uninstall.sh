#!/usr/bin/env bash
# 卸载 Mailu（默认保留数据目录，--purge 会连同数据一起删除）
set -uo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" && pwd)"
BASE="$(cd "$DIR/.." && pwd)"
# shellcheck source=../lib/common.sh
. "$BASE/lib/common.sh"
for lib in docker firewall tls mailu report; do
    [ -f "$BASE/lib/${lib}.sh" ] && . "$BASE/lib/${lib}.sh"
done
: "${MAILU_ROOT:=/mailu}"
mc_load_saved_config >/dev/null 2>&1 || true
purge="no"
while [ "$#" -gt 0 ]; do
    case "$1" in
        --purge) purge="yes" ;;
        --yes|-y) MAILU_ASSUME_YES="yes" ;;
        --root) shift; MAILU_ROOT="${1:-}" ;;
        *) mc_warn "忽略未知参数: $1" ;;
    esac
    shift
done
mc_detect_os
mc_require_root
mc_uninstall "$purge"