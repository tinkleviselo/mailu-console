#!/usr/bin/env bash
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