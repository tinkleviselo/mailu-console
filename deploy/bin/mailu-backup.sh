#!/usr/bin/env bash
# 备份 Mailu 数据（mailu.env / data / dkim / certs / webmail / filter）
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
while [ "$#" -gt 0 ]; do
    case "$1" in
        --dir) shift; MAILU_BACKUP_DIR="${1:-}" ;;
        --with-mail) MAILU_BACKUP_MAIL="yes" ;;
        --keep) shift; MAILU_BACKUP_KEEP="${1:-7}" ;;
        *) mc_warn "忽略未知参数: $1" ;;
    esac
    shift
done
mc_detect_os
mc_backup