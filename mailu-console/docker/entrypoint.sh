#!/usr/bin/env bash
# 中台容器入口：首次启动自动初始化（MC_AUTO_INSTALL=1），然后交给 Apache
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/html}"
CONFIG="$APP_DIR/config/config.php"

log() { printf '[entrypoint] %s\n' "$*"; }

if [ ! -f "$CONFIG" ]; then
    if [ "${MC_AUTO_INSTALL:-1}" = "1" ]; then
        log "未发现 config/config.php，开始自动初始化中台"
        if [ -z "${MC_ADMIN_PASS:-}" ]; then
            log "警告: 未设置 MC_ADMIN_PASS，将随机生成管理员密码（见下方输出）"
        fi
        php "$APP_DIR/app/cli/install.php" --json "${MC_INSTALL_ARGS:-}" || {
            log "自动初始化失败，容器退出。可设置 MC_AUTO_INSTALL=0 后手动访问 /setup"
            exit 1
        }
    else
        log "未初始化：请访问 /setup 完成安装（MC_AUTO_INSTALL=0）"
    fi
else
    log "已存在配置，跳过初始化"
fi

# 确保可写（挂载卷首次创建时属主可能是 root）
chown -R www-data:www-data "$APP_DIR/storage" "$APP_DIR/config" 2>/dev/null || true

log "启动 Apache"
exec "$@"