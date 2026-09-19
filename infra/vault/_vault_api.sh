# shellcheck shell=sh
#
# Vault HTTP API 的公共助手（T-114 从 bootstrap.sh 抽出）。
#
# ============================================================================
# 为什么用 curl + HTTP API 而不是 vault CLI
# ============================================================================
# 用得上这份助手的调用点有三个，而它们能用的工具不一样：
#   - GitHub Actions runner：Vault 是 service 容器，runner 上**没有** vault 二进制
#   - compose 的 vault-init 一次性容器：有 curl，没必要为了 CLI 换个大镜像
#   - compose 的 vault-policy 一次性容器（T-114）：同上，且与前者同一个镜像
# HTTP API 是三边都有的最大公约数。顺带地，CLI 的输出格式会随版本变，
# 而 API 的响应结构是有版本承诺的。
#
# ============================================================================
# 为什么是一份而不是两份
# ============================================================================
# bootstrap.sh 与 policy-sync.sh 都要「发一个带 token 的请求，同时拿到状态码与
# 响应体」以及「等 Vault 就绪」。抄一份过去必然漂 —— 而下面 api() 的
# 「状态码放最后一行」这个约定一旦两边不一致，症状是 status_of() 读到一行 JSON，
# 于是每个 case 都落进 *) 分支报「判断不了，中止」，跟权限问题分不开。
#
# 依赖：curl、jq。调用方自己 require。
#
# 用法（调用方必须先有 VAULT_ADDR；api 还要 VAULT_TOKEN）：
#   VAULT_DIR="$(cd "$(dirname "$0")" && pwd)"
#   . "${VAULT_DIR}/_vault_api.sh"

# ----------------------------------------------------------------------------
# 依赖检查
# ----------------------------------------------------------------------------
require() {
    command -v "$1" >/dev/null 2>&1 || {
        echo "✗ 缺少依赖：$1" >&2
        exit 1
    }
}

# ----------------------------------------------------------------------------
# HTTP 助手
# ----------------------------------------------------------------------------
# 把状态码与响应体一起拿回来：Vault 用状态码表达「成功/不存在/被拒」，
# 而响应体里才有错误详情，两个都要。状态码放最后一行，前面全是 body。
api() {
    _method="$1"
    _path="$2"
    _data="${3:-}"

    if [ -n "$_data" ]; then
        curl -sS -w '\n%{http_code}' -X "$_method" \
            -H "X-Vault-Token: ${VAULT_TOKEN}" \
            -H 'Content-Type: application/json' \
            -d "$_data" \
            "${VAULT_ADDR}/v1/${_path}"
    else
        curl -sS -w '\n%{http_code}' -X "$_method" \
            -H "X-Vault-Token: ${VAULT_TOKEN}" \
            "${VAULT_ADDR}/v1/${_path}"
    fi
}

status_of() { printf '%s' "$1" | tail -n 1; }
body_of() { printf '%s' "$1" | sed '$d'; }

# ----------------------------------------------------------------------------
# 等 Vault 就绪
# ----------------------------------------------------------------------------
# compose 的 depends_on: service_healthy 已经等过一轮，但 CI 的 service 容器
# healthcheck 语义略有不同，而「刚起来还没解封」会让后面每一步都莫名其妙地失败。
# 在这里等，报错信息才说得清是什么状况。
#
# ⚠️ 封印与未初始化**不自己退出**，而是 return 一个码，由调用方决定怎么办：
#   bootstrap.sh    致命（没解封就什么都做不了）
#   policy-sync.sh  跳过（主机重启后必然封印，那时对不了账是正常的，不是漂移）
#
#   0 就绪 · 1 超时或连不上 · 2 封印 · 3 未初始化
wait_for_vault() {
    echo "→ 等待 Vault 就绪（${VAULT_ADDR}）…"
    _attempt=0
    _health=000

    while [ "$_attempt" -lt 30 ]; do
        # sys/health 免认证。200 = 已初始化且已解封。
        _health="$(curl -sS -o /dev/null -w '%{http_code}' "${VAULT_ADDR}/v1/sys/health?standbyok=true" 2>/dev/null || echo 000)"

        case "$_health" in
            200) return 0 ;;
            501) return 3 ;;
            503) return 2 ;;
        esac

        _attempt=$((_attempt + 1))
        sleep 1
    done

    echo "✗ 等待 Vault 就绪超时（30s），最后一次 sys/health 返回 ${_health}。" >&2
    return 1
}
