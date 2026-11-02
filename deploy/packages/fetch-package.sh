#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# 下载 Mailu 官方安装包到本地 packages/cache（用于离线/批量部署）
# 用法: bash packages/fetch-package.sh [版本] [--mirror-prefix URL]
# ---------------------------------------------------------------------------
set -uo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" && pwd)"
BASE="$(cd "$DIR/.." && pwd)"
# shellcheck source=../lib/common.sh
. "$BASE/lib/common.sh"
for lib in docker mailu; do
    [ -f "$BASE/lib/${lib}.sh" ] && . "$BASE/lib/${lib}.sh"
done
VERSION="${1:-2024.06.61}"
: "${MAILU_MIRROR_PREFIX:=}"
shift || true
while [ "$#" -gt 0 ]; do
    case "$1" in
        --mirror-prefix) shift; MAILU_MIRROR_PREFIX="${1:-}" ;;
        --version) shift; VERSION="${1:-}" ;;
        *) : ;;
    esac
    shift
done
CACHE="$DIR/cache"
mkdir -p "$CACHE"
TARGET="$CACHE/mailu-${VERSION}.tar.gz"
mc_detect_os
mc_ensure_base_tools
urls=""
if [ -n "$MAILU_MIRROR_PREFIX" ]; then
    urls="$urls ${MAILU_MIRROR_PREFIX%/}/https://github.com/Mailu/Mailu/archive/refs/tags/${VERSION}.tar.gz"
fi
urls="$urls https://github.com/Mailu/Mailu/archive/refs/tags/${VERSION}.tar.gz"
urls="$urls https://codeload.github.com/Mailu/Mailu/tar.gz/refs/tags/${VERSION}"
# shellcheck disable=SC2086
if mc_download_first "$TARGET" $urls; then
    mc_ok "安装包已保存: $TARGET ($(du -h "$TARGET" | cut -f1))"
    mc_info "离线部署时该文件会被自动识别（脚本优先使用本地缓存）。"
else
    mc_die "安装包下载失败，请检查网络或使用 --mirror-prefix 指定加速地址"
fi