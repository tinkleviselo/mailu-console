#!/usr/bin/env bash
# 启动模拟 Mailu + PHP 内置服务器，跑中台端到端冒烟测试，结束后清理。
# 用法: bash tests/run_smoke.sh        （可设 PHP_PORT / MOCK_PORT / PHP）
set -uo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" && pwd)"
ROOT="$(cd "$SELF_DIR/.." && pwd)"
WORK="$ROOT/tests/.work"
PHP_PORT="${PHP_PORT:-8099}"
MOCK_PORT="${MOCK_PORT:-9099}"
PHP_BIN="${PHP:-php}"

command -v "$PHP_BIN" >/dev/null 2>&1 || { echo "需要 php（当前 PHP=$PHP_BIN）" >&2; exit 1; }
command -v python3  >/dev/null 2>&1 || { echo "需要 python3" >&2; exit 1; }

rm -rf "$WORK"; mkdir -p "$WORK"
cp -a "$ROOT/mailu-console" "$WORK/console"
rm -f "$WORK/console/config/config.php" "$WORK/console/storage/"*.sqlite* 2>/dev/null || true

cleanup() {
    [ -n "${PHP_PID:-}" ] && kill "$PHP_PID" 2>/dev/null
    [ -n "${MOCK_PID:-}" ] && kill "$MOCK_PID" 2>/dev/null
}
trap cleanup EXIT INT TERM

"$PHP_BIN" -S "127.0.0.1:$PHP_PORT" -t "$WORK/console/public" "$WORK/console/public/router.php" \
    >"$WORK/php.log" 2>&1 &
PHP_PID=$!
python3 "$ROOT/tests/mock_mailu.py" "$MOCK_PORT" >"$WORK/mock.log" 2>&1 &
MOCK_PID=$!

# 等待 PHP 端口就绪
i=0
while [ "$i" -lt 40 ]; do
    if python3 -c "
import socket, sys
s = socket.socket()
s.settimeout(1)
try:
    s.connect(('127.0.0.1', $PHP_PORT)); sys.exit(0)
except Exception:
    sys.exit(1)
" 2>/dev/null; then
        break
    fi
    i=$((i + 1)); sleep 0.5
done
sleep 1

CONSOLE_BASE="http://127.0.0.1:$PHP_PORT" MOCK_BASE="http://127.0.0.1:$MOCK_PORT" \
    python3 "$ROOT/tests/smoke_test.py"
RC=$?

if [ "$RC" -ne 0 ]; then
    echo '--- php.log (tail) ---' >&2;  tail -n 30 "$WORK/php.log"  >&2 || true
    echo '--- mock.log (tail) ---' >&2; tail -n 30 "$WORK/mock.log" >&2 || true
fi
exit "$RC"