#!/usr/bin/env bash
#
# App Links 域（app.n-cards.de）的部署后冒烟测试（T-106 / ADR-0016）。
#
# ============================================================================
# 它验的是 T-106 验收标准的**前半条**
# ============================================================================
# 任务卡写的是「集成测试断言 GET 与 HEAD 不改变 consumed_at」。那条在 PHPUnit
# 里写不出来 —— 落地页根本不在后端，它是一份由 Caddy 直接吐出的静态文件。
# 于是那条要求被拆成三个强制点，这个脚本是其中之一（在**真栈**上）：
#
#   ① backend/tests/Api/RouteInventoryTest —— 后端不注册任何 /l/ 路由
#   ② backend/tests/Api/MagicConsumeEndpointTest —— 本端点只接 POST
#   ③ **本脚本** —— 落地页与 assetlinks.json 在公网上真的可达且是静态的
#
# 与 smoke-staging.sh 分开是因为它们打的是**两个不同的域**（API 域与 App Links 域），
# 而那两个域的 CSP、证书、期望的 Content-Type 都不一样。塞进同一个脚本要么
# 参数变成两个、要么断言里到处是 if —— 两者都比多一个文件差。
#
#   用法: scripts/ci/smoke-app-site.sh https://app.staging.n-cards.de
#
#   SMOKE_INSECURE=1   跳过证书校验。**仅用于 ACME 排练**，理由同 smoke-staging.sh。
#
# 依赖: curl
#
set -euo pipefail

BASE_URL="${1:-}"
if [ -z "$BASE_URL" ]; then
    echo "用法: $0 <base_url>   例：$0 https://app.staging.n-cards.de" >&2
    exit 2
fi

BASE_URL="${BASE_URL%/}"

CURL_OPTS=(--silent --show-error --max-time 20)
if [ "${SMOKE_INSECURE:-0}" = "1" ]; then
    echo "⚠️ SMOKE_INSECURE=1：跳过证书校验。这只该出现在 ACME 排练里，不该出现在验收里。"
    CURL_OPTS+=(--insecure)
fi

failures=0
fail() { echo "  !! $1"; failures=$((failures + 1)); }
pass() { echo "  ✓ $1"; }

# 一个形状合法但从来没有存在过的令牌（43 个 base64url 字符）。
# ⚠️ 刻意用**假**令牌：这个脚本在每次部署后都跑，拿真令牌来打等于
# 每次部署都消费掉一次真实登录 —— 而它本来就该证明「GET 什么都不消费」。
FAKE_TOKEN="0000000000000000000000000000000000smoke-test"
FAKE_TOKEN="${FAKE_TOKEN:0:43}"

status_of() {
    curl "${CURL_OPTS[@]}" -o /dev/null -w '%{http_code}' "$1"
}

# ---------------------------------------------------------------------------
# 1. Magic Link 落地页：GET / HEAD / 预取都是 200，且都是静态 HTML
# ---------------------------------------------------------------------------
echo "[1/4] Magic Link 落地页 $BASE_URL/l/magic/<token>"

magic_url="$BASE_URL/l/magic/$FAKE_TOKEN"

code="$(status_of "$magic_url")"
if [ "$code" = "200" ]; then
    pass "GET → 200"
else
    fail "GET → $code（期望 200）"
fi

# ⚠️ HEAD 与预取是这条验收标准的核心：企业邮件安全网关就是这么打的。
code="$(curl "${CURL_OPTS[@]}" -o /dev/null -I -w '%{http_code}' "$magic_url")"
if [ "$code" = "200" ]; then
    pass "HEAD → 200"
else
    fail "HEAD → $code（期望 200）"
fi

code="$(curl "${CURL_OPTS[@]}" -o /dev/null -w '%{http_code}' \
    -H 'Purpose: prefetch' -H 'Sec-Purpose: prefetch' "$magic_url")"
if [ "$code" = "200" ]; then
    pass "预取（Purpose: prefetch）→ 200"
else
    fail "预取 → $code（期望 200）"
fi

# ⚠️ 令牌**不得**出现在响应体里。出现了就说明这一页变成了服务端渲染 ——
# 那意味着后端重新参与了这条路径，而整个 ADR-0016 的论证就是它不参与。
if curl "${CURL_OPTS[@]}" "$magic_url" | grep -q "$FAKE_TOKEN"; then
    fail "响应体里出现了令牌 —— 落地页不再是静态的？见 ADR-0016"
else
    pass "响应体不含令牌（令牌只由浏览器侧的 JS 从路径里读）"
fi

hdrs="$(curl "${CURL_OPTS[@]}" -o /dev/null -D - "$magic_url")"
lower_hdrs="$(printf '%s' "$hdrs" | tr '[:upper:]' '[:lower:]')"

# 令牌在 URL 路径里，所以这两条不是「最佳实践」，是要求：
#   no-store    —— 不许任何中间层把带令牌的 URL 连同响应缓存下来
#   no-referrer —— 页面上有指向 Play 商店的外链，默认策略会把整条 URL 发过去
for pair in "cache-control:no-store" "referrer-policy:no-referrer"; do
    name="${pair%%:*}"
    want="${pair#*:}"
    if printf '%s' "$lower_hdrs" | grep -qi "^${name}:.*${want}"; then
        pass "$name 含 $want"
    else
        fail "$name 未含 $want —— 令牌在 URL 路径里，这两条是安全要求"
    fi
done

# ---------------------------------------------------------------------------
# 2. assetlinks.json 可达且是 JSON
# ---------------------------------------------------------------------------
echo "[2/4] $BASE_URL/.well-known/assetlinks.json"

assetlinks_hdrs="$(curl "${CURL_OPTS[@]}" -o /dev/null -D - "$BASE_URL/.well-known/assetlinks.json")"
code="$(printf '%s' "$assetlinks_hdrs" | head -1 | awk '{print $2}')"

if [ "$code" = "200" ]; then
    pass "GET → 200"
else
    fail "GET → $code（期望 200）—— App Links 校验会直接失败"
fi

# ⚠️ Content-Type 必须是 application/json。Android 的校验器对这一条很严格，
# 而「文件在那儿但类型错了」的症状与「文件不在」完全一样。
if printf '%s' "$assetlinks_hdrs" | tr '[:upper:]' '[:lower:]' | grep -qi '^content-type:.*application/json'; then
    pass "Content-Type: application/json"
else
    fail "Content-Type 不是 application/json —— Android 的 App Links 校验会失败"
fi

# 必须是合法 JSON 数组。空数组是**允许**的（指纹还没填，见 site/README.md），
# 但语法错误不是。
body="$(curl "${CURL_OPTS[@]}" "$BASE_URL/.well-known/assetlinks.json")"
if printf '%s' "$body" | python3 -c 'import json,sys; d=json.load(sys.stdin); sys.exit(0 if isinstance(d, list) else 1)' 2>/dev/null; then
    pass "是合法的 JSON 数组"
else
    fail "不是合法的 JSON 数组：$(printf '%s' "$body" | head -c 120)"
fi

# ---------------------------------------------------------------------------
# 3. 另外两个落地页（T-104 / T-105 已经把它们写进真实用户的邮件里了）
# ---------------------------------------------------------------------------
echo "[3/4] 既有邮件里的两个落地页"

for path in /l/devices /l/security; do
    code="$(status_of "$BASE_URL$path")"
    if [ "$code" = "200" ]; then
        pass "$path → 200"
    else
        fail "$path → $code（这个链接已经在用户收到的邮件里了）"
    fi
done

# ---------------------------------------------------------------------------
# 4. 这个域下**没有** API
# ---------------------------------------------------------------------------
echo "[4/4] App Links 域不暴露 API"

# ⚠️ 这一条是 ADR-0016 的结构保证在公网上的体现：这个站点块只 file_server，
# 没有 reverse_proxy。真有人给它加了反代，下面这条会从 404 变成别的。
code="$(status_of "$BASE_URL/v1/auth/magic/consume")"
if [ "$code" = "404" ]; then
    pass "/v1/* → 404（这个域不反代 app:8080）"
else
    fail "/v1/auth/magic/consume → $code（期望 404）—— App Links 域不该反代 API，见 ADR-0016"
fi

echo
if [ "$failures" -gt 0 ]; then
    echo "冒烟测试失败：$failures 条"
    exit 1
fi

echo "冒烟测试通过。"
