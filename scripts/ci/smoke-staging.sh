#!/usr/bin/env bash
#
# 部署后冒烟测试（T-012 的验收标准）。
#
# 任务卡原文：「一次 main 合入能自动部署到 staging 并通过冒烟测试；`curl -I` 能
# 看到全部安全头；`X-Powered-By`/`Server` 版本回显已禁用。」这个脚本就是那三条。
#
# **从外面打公网域名**，不在主机上打 localhost —— 只有走公网才同时验了
# DNS、TLS 证书链、Caddy 的头、以及 80→443 重定向。打 localhost 的话，
# 证书没签出来也照样绿，而那正是最容易出问题的一环。
#
# 探的是 /health/live 而不是某条业务路由：它是本次的「hello world」。
# 理由见 docs/tasks/M0.md 的 T-012 交付产物段 —— 后端的 RouteInventoryTest
# 要求每条路由要么在 /v1/ 下、要么在显式白名单里，为一句 hello world 去动
# 那张表不值得，而 /health/live 本来就是免 X-Client 的、裸 curl 可达的。
#
#   用法: scripts/ci/smoke-staging.sh https://api.staging.n-cards.de
#
#   SMOKE_INSECURE=1   跳过证书校验。**仅用于 ACME 排练**（Let's Encrypt 的
#                      staging 目录签出来的证书不受公共信任）。
#                      ⚠️ 验收必须在不带这个变量的情况下跑。
#
# 依赖: curl
#
set -euo pipefail

BASE_URL="${1:-}"
if [ -z "$BASE_URL" ]; then
    echo "用法: $0 <base_url>   例：$0 https://api.staging.n-cards.de" >&2
    exit 2
fi

BASE_URL="${BASE_URL%/}"
HOST="${BASE_URL#https://}"
HOST="${HOST#http://}"

CURL_OPTS=(--silent --show-error --max-time 20)
if [ "${SMOKE_INSECURE:-0}" = "1" ]; then
    echo "⚠️ SMOKE_INSECURE=1：跳过证书校验。这只该出现在 ACME 排练里，不该出现在验收里。"
    CURL_OPTS+=(--insecure)
fi

failures=0
fail() { echo "  !! $1"; failures=$((failures + 1)); }
pass() { echo "  ✓ $1"; }

# ---------------------------------------------------------------------------
# 1. /health/live 经真实 TLS 返回 200
# ---------------------------------------------------------------------------
echo "[1/4] GET $BASE_URL/health/live"

resp="$(mktemp)"; hdrs="$(mktemp)"
trap 'rm -f "$resp" "$hdrs"' EXIT

status="$(curl "${CURL_OPTS[@]}" -o "$resp" -D "$hdrs" -w '%{http_code}' "$BASE_URL/health/live" || echo 000)"

if [ "$status" = "200" ]; then
    pass "HTTP $status"
else
    fail "期望 200，实得 $status"
    echo "     —— 后面的断言基于这次响应，先修这条"
fi

body="$(tr -d '[:space:]' < "$resp")"
if [ "$body" = '{"status":"ok"}' ]; then
    pass "响应体 {\"status\":\"ok\"}"
else
    fail "响应体不是 {\"status\":\"ok\"}，实得：$(head -c 200 "$resp")"
fi

# ⚠️ 头名统一转小写再比。HTTP/2 的头名在协议层就是小写的，curl 原样输出；
# HTTP/1.1 下 Caddy 发的是 Strict-Transport-Security。不转的话这个脚本
# 会在站点从 h1 升到 h2 的那天突然全红，而什么都没坏。
lower_hdrs="$(tr '[:upper:]' '[:lower:]' < "$hdrs")"

# ---------------------------------------------------------------------------
# 2. §7.4 的五个安全响应头
# ---------------------------------------------------------------------------
echo "[2/4] 安全响应头（§7.4）"

check_header() {
    local name="$1" expected="$2" actual
    actual="$(printf '%s' "$lower_hdrs" | grep -i "^${name}:" | head -1 | cut -d: -f2- | sed 's/^ *//; s/\r$//')"
    if [ -z "$actual" ]; then
        fail "缺少 $name"
    elif [ "$actual" = "$expected" ]; then
        pass "$name: $actual"
    else
        fail "$name 期望 “$expected”，实得 “$actual”"
    fi
}

# HSTS 的值精确匹配 —— max-age 短了或少了 preload 都不算数（任务卡写死了这个值）。
check_header "strict-transport-security" "max-age=63072000; includesubdomains; preload"
check_header "content-security-policy" "default-src 'none'"
check_header "x-content-type-options" "nosniff"
check_header "referrer-policy" "no-referrer"
check_header "x-frame-options" "deny"

# ---------------------------------------------------------------------------
# 3. 版本回显已禁用
# ---------------------------------------------------------------------------
echo "[3/4] 版本回显（§7.4）"

for h in server x-powered-by via; do
    if printf '%s' "$lower_hdrs" | grep -qi "^${h}:"; then
        fail "$h 头仍然存在：$(printf '%s' "$lower_hdrs" | grep -i "^${h}:" | head -1)"
    else
        pass "无 $h 头"
    fi
done

# ---------------------------------------------------------------------------
# 4. HTTP 自动重定向到 HTTPS
# ---------------------------------------------------------------------------
# Caddy 见到裸域名（CADDY_SITE_ADDRESS 不带 scheme）才会开这个重定向。
# 写成 https:// 也能跑，但那样就没有 80→443 了 —— 这一条就是防那种配置漂移。
echo "[4/4] HTTP → HTTPS 重定向"

redirect_status="$(curl "${CURL_OPTS[@]}" -o /dev/null -w '%{http_code}' "http://${HOST}/health/live" || echo 000)"
case "$redirect_status" in
    301|302|307|308) pass "HTTP $redirect_status（重定向到 HTTPS）" ;;
    *) fail "期望 3xx 重定向，实得 $redirect_status —— 确认 CADDY_SITE_ADDRESS 不带 scheme" ;;
esac

# ---------------------------------------------------------------------------
echo
if [ "$failures" -gt 0 ]; then
    echo "!! 冒烟测试失败：$failures 条断言不通过"
    echo "   处置见 docs/runbooks/deploy-and-rollback.md"
    exit 1
fi

echo "✓ 冒烟测试通过：$BASE_URL"
