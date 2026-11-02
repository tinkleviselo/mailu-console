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
    local dir="${MAILU_CONFIG_DIR:-/etc/mailu-deploy}"
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
    local file="${MAILU_CONFIG_DIR:-/etc/mailu-deploy}/mailu-deploy.conf"
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
