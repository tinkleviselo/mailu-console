#!/usr/bin/env bash
# ===========================================================================
#  部署脚本自检 —— 在任意 Linux / macOS / Git-Bash 上运行，不需要 Docker、不需要 root
#
#  用法:
#      bash deploy/tests/selfcheck.sh                 # 生成到临时目录并校验
#      TEST_ROOT=/tmp/x bash deploy/tests/selfcheck.sh
#
#  它会用 deploy/install.sh 里的函数生成 docker-compose.yml 与 mailu.env，
#  然后逐项断言（镜像命名、端口、DNSSEC resolver、限流单位、管理员变量等）。
# ===========================================================================
set -uo pipefail

GOOD=0; BAD=0; SKIP=0
check() { if [ "$2" = "0" ]; then GOOD=$((GOOD+1)); printf 'PASS  %s\n' "$1"; else BAD=$((BAD+1)); printf 'FAIL  %s\n' "$1"; fi; }
skip()  { SKIP=$((SKIP+1)); printf 'SKIP  %s\n' "$1"; }

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" && pwd)"
DEPLOY_DIR="$(cd "$SELF_DIR/.." && pwd)"
TEST_ROOT="${TEST_ROOT:-$(mktemp -d 2>/dev/null || echo "/tmp/mailu-selfcheck-$$")}"
ROOT="$TEST_ROOT/mailu"
rm -rf "$TEST_ROOT"; mkdir -p "$ROOT"

echo "== 载入部署脚本（source 模式，不执行安装） =="
# install.sh 在被 source 时只加载函数、不跑主流程
# shellcheck disable=SC1091
. "$DEPLOY_DIR/install.sh"

# ---- 测试用参数（全部为假值，仅用于生成配置） ----
export MAILU_DOMAIN="example.com"
export MAILU_HOSTNAMES="mail.example.com"
export MAILU_ROOT="$ROOT"
export MAILU_LOG="$ROOT/deploy.log"
export MAILU_CONFIG_DIR="$ROOT/etc"
export MAILU_TLS_FLAVOR="letsencrypt"
export MAILU_TIMEZONE="Asia/Shanghai"
export MAILU_WEBSITE="https://example.com"
export MAILU_WEBMAIL="snappymail"
export MAILU_ADMIN_GUI="true"
export MAILU_API="true"
export MAILU_WEBDAV="none"
export MAILU_ANTIVIRUS="none"
export MAILU_FETCHMAIL_ENABLED="false"
export MAILU_SITE_NAME="Example Mail"
export MAILU_AUTH_RATELIMIT_IP="50"
export MAILU_AUTH_RATELIMIT_USER="100"
export MAILU_DISABLE_STATISTICS="True"
export MAILU_RELAYNETS=""
export MAILU_RELAYHOST=""
export MAILU_DMARC_RUA="postmaster"
export MAILU_DMARC_RUF="postmaster"
export MAILU_COMPOSE_PROJECT_NAME="mailu"
export MAILU_REJECT_UNLISTED_RECIPIENT="yes"
export MAILU_SPAM_THRESHOLD="80"
export MAILU_MESSAGE_SIZE_LIMIT="52428800"
export MAILU_MESSAGE_RATELIMIT="200/day"
export MAILU_SUBNET="192.168.203.0/24"
export MAILU_BIND_ADDRESS4="0.0.0.0"
export MAILU_DOCKER_ORG="ghcr.io/mailu"
export MAILU_VERSION="2024.06.61"
export MAILU_SECRET_KEY="selfcheck-not-a-real-key"
export MAILU_RECIPIENT_DELIMITER="+"
export MAILU_FETCHMAIL_DELAY="600"
export MAILU_COMPRESSION=""
export MAILU_REAL_IP_HEADER=""
export MAILU_REAL_IP_FROM=""
export MAILU_WELCOME="false"
export MAILU_POSTMASTER="postmaster"
export MAILU_ADMIN_USER="admin"
export MAILU_ADMIN_PASS="selfcheck-not-a-real-pass"
export MAILU_API_TOKEN="selfcheck-not-a-real-token"
export MAILU_RESOLVER="yes"
export MAILU_DNS_UPSTREAM="9.9.9.9"
export MAILU_DRY_RUN="no"

echo "== 生成配置 =="
mc_mailu_dirs >/dev/null 2>&1
mc_write_compose >/dev/null
mc_write_env >/dev/null

check "docker-compose.yml 已生成" "$([ -s "$ROOT/docker-compose.yml" ] && echo 0 || echo 1)"
check "mailu.env 已生成" "$([ -s "$ROOT/mailu.env" ] && echo 0 || echo 1)"
check "数据目录已创建" "$([ -d "$ROOT/data" ] && [ -d "$ROOT/dkim" ] && [ -d "$ROOT/certs" ] && echo 0 || echo 1)"

echo "== compose 内容 =="
grep -q 'ghcr.io/mailu/nginx:2024.06.61' "$ROOT/docker-compose.yml"; check "镜像前缀与版本" "$?"
grep -q 'subnet: 192.168.203.0/24'        "$ROOT/docker-compose.yml"; check "容器网段" "$?"
grep -q 'ghcr.io/mailu/webmail:2024.06.61' "$ROOT/docker-compose.yml"; check "webmail 使用 2.x 统一镜像" "$?"
grep -q -- '- "0.0.0.0:25:25"'             "$ROOT/docker-compose.yml"; check "暴露 25 端口" "$?"
grep -q '  resolver:'                      "$ROOT/docker-compose.yml"; check "含 unbound resolver 服务" "$?"
grep -q 'ipv4_address: 192.168.203.254'    "$ROOT/docker-compose.yml"; check "resolver 静态 IP" "$?"
dns_count="$(grep -c '^    dns:$' "$ROOT/docker-compose.yml")"
check "服务注入 dns（实际 $dns_count）" "$([ "${dns_count:-0}" -ge 6 ] && echo 0 || echo 1)"
check "无重复 depends_on 键" "$([ "$(grep -c '^    depends_on:$' "$ROOT/docker-compose.yml")" -le 6 ] && echo 0 || echo 1)"

echo "== mailu.env 内容 =="
grep -q 'DOMAIN=example.com'          "$ROOT/mailu.env"; check "主域名" "$?"
grep -q 'HOSTNAMES=mail.example.com'  "$ROOT/mailu.env"; check "主机名" "$?"
grep -q 'TLS_FLAVOR=letsencrypt'      "$ROOT/mailu.env"; check "TLS 模式" "$?"
grep -q 'WEBMAIL=snappymail'          "$ROOT/mailu.env"; check "webmail 类型" "$?"
grep -q 'API=true'                    "$ROOT/mailu.env"; check "启用 API" "$?"
grep -q 'API_TOKEN=selfcheck-not-a-real-token' "$ROOT/mailu.env"; check "写入 API 令牌" "$?"
grep -q 'AUTH_RATELIMIT_IP=50/hour'   "$ROOT/mailu.env"; check "限流单位（hour）" "$?"
grep -q 'AUTH_RATELIMIT_USER=100/day' "$ROOT/mailu.env"; check "限流单位（day）" "$?"
grep -q 'INITIAL_ADMIN_ACCOUNT=admin' "$ROOT/mailu.env"; check "初始管理员账号" "$?"
grep -q 'INITIAL_ADMIN_DOMAIN=example.com' "$ROOT/mailu.env"; check "初始管理员域名" "$?"
grep -q 'TZ=Asia/Shanghai'            "$ROOT/mailu.env"; check "时区" "$?"

echo "== Mailu 1.x 兼容镜像命名 =="
( MAILU_VERSION="1.9.51"; mc_write_compose >/dev/null 2>&1 )
grep -q 'image: mailu/nginx:1.9.51'      "$ROOT/docker-compose.yml"; check "1.x 主镜像名" "$?"
grep -q 'image: mailu/snappymail:1.9.51' "$ROOT/docker-compose.yml"; check "1.x webmail 镜像名" "$?"
( MAILU_VERSION="2024.06.61"; mc_write_compose >/dev/null 2>&1 )
grep -q 'ghcr.io/mailu/nginx:2024.06.61' "$ROOT/docker-compose.yml"; check "恢复 2.x 镜像名" "$?"

echo "== 函数单元检查 =="
check "mc_primary_hostname" "$([ "$(mc_primary_hostname)" = "mail.example.com" ] && echo 0 || echo 1)"
val="$(mc_json_value '{"dns_mx":"mail.example.com","dns_dkim":"v=DKIM1; p=KEY"}' dns_dkim)"
check "mc_json_value 解析 DKIM" "$([ "$val" = "v=DKIM1; p=KEY" ] && echo 0 || echo 1)"
pw="$(mc_random_password 16)"; check "mc_random_password 长度" "$([ "${#pw}" -eq 16 ] && echo 0 || echo 1)"
hex="$(mc_random_hex 8)";      check "mc_random_hex 长度"      "$([ "${#hex}" -eq 16 ] && echo 0 || echo 1)"
mkdir -p "$ROOT/tpl"
printf 'a=@A@\nb=@B@\npath=@P@\n' > "$ROOT/tpl/in.txt"
mc_render_template "$ROOT/tpl/in.txt" "$ROOT/tpl/out.txt" "A=1" "B=two words" "P=/a/b&c"
check "mc_render_template 替换（含 & 与 /）" "$(grep -q 'path=/a/b&c' "$ROOT/tpl/out.txt" && echo 0 || echo 1)"
if mc_cmd_exists openssl; then
    mc_generate_selfsigned >/dev/null 2>&1
    check "mc_generate_selfsigned 生成证书" "$([ -s "$ROOT/certs/cert.pem" ] && [ -s "$ROOT/certs/key.pem" ] && echo 0 || echo 1)"
    openssl x509 -in "$ROOT/certs/cert.pem" -noout -subject >/dev/null 2>&1; check "证书可被 openssl 解析" "$?"
    cert_days="$(mc_cert_days_left "$ROOT/certs/cert.pem")"
    check "证书有效期 > 365 天（$cert_days）" "$([ -n "$cert_days" ] && [ "$cert_days" -gt 365 ] && echo 0 || echo 1)"
else
    skip "自签证书相关检查（本机无 openssl）"
fi
mc_save_config >/dev/null 2>&1
check "mc_save_config 写入参数文件" "$([ -s "$ROOT/etc/mailu-deploy.conf" ] && echo 0 || echo 1)"

echo
echo "==== 自检结果: PASS=$GOOD FAIL=$BAD SKIP=$SKIP ===="
[ "$BAD" -eq 0 ] || exit 1
exit 0
