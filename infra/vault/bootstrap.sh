#!/usr/bin/env sh
#
# Vault 初始化：Transit 引擎、三把 key、JWT 的 KV、两份 policy、AppRole（T-005 / §5.3 / §17.4）。
#
# ============================================================================
# 为什么用 curl + HTTP API 而不是 vault CLI
# ============================================================================
# 同一个脚本有两个调用点，而它们能用的工具不一样：
#   - GitHub Actions runner：Vault 是 service 容器，runner 上**没有** vault 二进制
#   - compose 的 vault-init 一次性容器：有 curl，没必要为了 CLI 换个大镜像
# HTTP API 是两边都有的最大公约数。顺带地，CLI 的输出格式会随版本变，
# 而 API 的响应结构是有版本承诺的。
#
# 依赖：curl、jq、openssl。缺任何一个都在开头就报错退出（见下方 require）。
#
# ============================================================================
# 幂等
# ============================================================================
# scripts/README.md 对仓库脚本的要求：可重复执行、失败非零退出、不静默吞错。
# 这个脚本尤其需要 —— compose 的 vault-init 每次 `docker compose up` 都会跑一遍。
#
# ⚠️ 幂等在这里有一条特殊的含义：**绝不覆盖已存在的密钥材料**。
# 重跑一次就换掉 JWT 签名密钥，等于把全部在线会话踢下线（§7.1）；
# 重建 transit key 更糟，全部既有密文将永远解不开。
# 所以每个「创建」动作前都先探测存在性，已存在就跳过并说明。
#
# ============================================================================
# 用法
# ============================================================================
#   本地：docker compose up -d vault-init        # 自动跑，见 docker-compose.base.yml
#   手动：VAULT_ADDR=http://127.0.0.1:8200 VAULT_TOKEN=dev-only-root-token ./bootstrap.sh
#   生产：先人工 unseal（docs/runbooks/vault-unseal.md），再用**root token** 跑一次。
#         跑完请立即吊销那个 root token —— runbook 里有这一步。
#
# 本脚本需要 root 或等价权限（要建 mount、写 policy、开 auth method），
# 这**不是** ncards-app 那份最小权限 policy 能做的事，也不该是。
set -eu

VAULT_ADDR="${VAULT_ADDR:-http://127.0.0.1:8200}"
POLICY_DIR="$(cd "$(dirname "$0")" && pwd)/policies"

# JWT 密钥在 KV 里的位置。T-104 的 TokenIssuer 从这里读。
JWT_KV_PATH="ncards/jwt/current"

require() {
    command -v "$1" >/dev/null 2>&1 || {
        echo "✗ 缺少依赖：$1" >&2
        exit 1
    }
}

require curl
require jq
require openssl

if [ -z "${VAULT_TOKEN:-}" ]; then
    echo "✗ 需要 VAULT_TOKEN（root 或具备 sys/mounts、sys/policy、sys/auth 权限的 token）。" >&2
    exit 1
fi

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

# 发一个必须成功的写请求；非 2xx 就带着 Vault 的错误详情退出。
must_write() {
    _label="$1"
    _path="$2"
    _data="${3:-}"

    _response="$(api POST "$_path" "$_data")"
    _status="$(status_of "$_response")"

    case "$_status" in
        2*) ;;
        *)
            echo "✗ ${_label} 失败（HTTP ${_status}）：$(body_of "$_response")" >&2
            exit 1
            ;;
    esac
}

# 某个路径是否已存在（GET 返回 2xx）。
exists() {
    _status="$(status_of "$(api GET "$1")")"
    case "$_status" in
        2*) return 0 ;;
        *) return 1 ;;
    esac
}

# ----------------------------------------------------------------------------
# 0. 等 Vault 就绪
# ----------------------------------------------------------------------------
# compose 的 depends_on: service_healthy 已经等过一轮，但 CI 的 service 容器
# healthcheck 语义略有不同，而「刚起来还没解封」会让下面每一步都莫名其妙地失败。
# 在这里等，报错信息才说得清是什么状况。
echo "→ 等待 Vault 就绪（${VAULT_ADDR}）…"
_attempt=0
while [ "$_attempt" -lt 30 ]; do
    # sys/health 免认证。200 = 已初始化且已解封。
    _health="$(curl -sS -o /dev/null -w '%{http_code}' "${VAULT_ADDR}/v1/sys/health?standbyok=true" 2>/dev/null || echo 000)"

    case "$_health" in
        200) break ;;
        501)
            echo "✗ Vault 未初始化。生产请先按 docs/runbooks/vault-unseal.md 初始化并 unseal。" >&2
            exit 1
            ;;
        503)
            echo "✗ Vault 处于封印状态。请先人工 unseal（docs/runbooks/vault-unseal.md）。" >&2
            exit 1
            ;;
    esac

    _attempt=$((_attempt + 1))
    sleep 1
done

if [ "$_attempt" -ge 30 ]; then
    echo "✗ 等待 Vault 就绪超时（30s），最后一次 sys/health 返回 ${_health}。" >&2
    exit 1
fi

# ----------------------------------------------------------------------------
# 1. Transit 引擎
# ----------------------------------------------------------------------------
if exists "sys/mounts/transit"; then
    echo "✓ transit 引擎已启用，跳过。"
else
    must_write ' 启用 transit 引擎' 'sys/mounts/transit' '{"type":"transit"}'
    echo "✓ 已启用 transit 引擎。"
fi

# ----------------------------------------------------------------------------
# 2. 三把 key（§5.3 密钥清单）
# ----------------------------------------------------------------------------
# ⚠️ 已存在就**绝不**重建。Transit 的 POST transit/keys/<name> 对已存在的 key
# 本身就是 no-op，但这里仍然显式先探测再建 —— 依赖「某个 API 恰好是幂等的」
# 这种隐含前提，在一次 Vault 升级之后可能悄悄变成「重置密钥」。
create_key() {
    _name="$1"
    _payload="$2"
    _purpose="$3"

    if exists "transit/keys/${_name}"; then
        echo "✓ transit key ${_name} 已存在，跳过（${_purpose}）。"
        return
    fi

    must_write "创建 transit key ${_name}" "transit/keys/${_name}" "$_payload"
    echo "✓ 已创建 transit key ${_name}（${_purpose}）。"
}

# AES-256-GCM96，12 个月轮换（§5.3）。deletion_allowed 默认就是 false，
# 显式带上是为了让「这把 key 删不掉」在配置里可见 ——
# 删掉等于全部既有密文永久不可读。
create_key ncards-card \
    '{"type":"aes256-gcm96","deletion_allowed":false}' \
    'cards.barcode_value_encrypted / note_encrypted'

create_key ncards-pii \
    '{"type":"aes256-gcm96","deletion_allowed":false}' \
    'users.email_encrypted'

# ⚠️⚠️ ncards-hmac **绝不轮换**（§5.3 密钥清单最后一列）。
#
# 它的输出是**查找键**：users.email_hash 上有 UNIQUE 约束，
# cards.barcode_value_fingerprint 用来判重复卡。换了 key，同一个邮箱算出的
# hash 就变了，于是全部既有行都找不回来 —— 登录查不到用户，去重失效。
# 而且没有补救：HMAC 是单向的，没有「用旧 key 解开再用新 key 算」这回事。
#
# Vault 没有「禁止轮换」这个开关，所以这条纪律由三处共同保证：
#   1. ncards-app policy 没有 rotate 权限（infra/vault/policies/ncards-app.hcl）
#   2. CryptoKey::isRotatable() 对它返回 false，T-404 的 rewrap 任务据此跳过
#   3. docs/runbooks/key-rotation.md（T-404）必须把这条写在最前面
create_key ncards-hmac \
    '{"type":"hmac","key_size":32,"deletion_allowed":false}' \
    'email_hash / code_hash / fingerprint 的 pepper —— 绝不轮换'

# ----------------------------------------------------------------------------
# 3. KV v2 + JWT 签名密钥（§5.3 密钥清单第四行）
# ----------------------------------------------------------------------------
# T-005 只负责把密钥**放到位**，不写读取侧 —— 那是 T-104（TokenIssuer）的事。
# 先建好，T-104 开工当天就能用。
if exists "sys/mounts/secret"; then
    echo "✓ kv 引擎（secret/）已启用，跳过。"
else
    must_write '启用 kv-v2 引擎' 'sys/mounts/secret' '{"type":"kv","options":{"version":"2"}}'
    echo "✓ 已启用 kv-v2 引擎（secret/）。"
fi

# ⚠️ 已存在就绝不覆盖：换掉签名密钥 = 全部在线 access token 立刻失效（§7.1），
# 而这个脚本每次起栈都会跑。
if exists "secret/data/${JWT_KV_PATH}"; then
    echo "✓ JWT 签名密钥已存在，跳过（绝不覆盖 —— 覆盖会踢掉全部在线会话）。"
else
    # EdDSA / Ed25519（§5.3）。私钥只在这个子 shell 里存在，不落盘。
    _jwt_private="$(openssl genpkey -algorithm ed25519 2>/dev/null)"
    _jwt_public="$(printf '%s' "$_jwt_private" | openssl pkey -pubout 2>/dev/null)"
    # kid 从公钥派生：确定性的，且不泄露任何私钥信息。
    # T-104 的 JWK Set 会用它做 key id，双密钥重叠期（§5.3：24h）靠它区分新旧。
    _jwt_kid="$(printf '%s' "$_jwt_public" | openssl dgst -sha256 -hex | sed 's/.*= *//' | cut -c1-16)"

    # 用 jq 组 JSON —— PEM 里有换行，手工拼字符串必然拼坏。
    _jwt_payload="$(jq -n \
        --arg private "$_jwt_private" \
        --arg public "$_jwt_public" \
        --arg kid "$_jwt_kid" \
        '{data: {private_key: $private, public_key: $public, kid: $kid, algorithm: "EdDSA"}}')"

    must_write '写入 JWT 签名密钥' "secret/data/${JWT_KV_PATH}" "$_jwt_payload"
    echo "✓ 已生成并写入 JWT 签名密钥（Ed25519，kid=${_jwt_kid}）。"

    unset _jwt_private _jwt_public _jwt_payload
fi

# ----------------------------------------------------------------------------
# 4. Policy（§17.4）
# ----------------------------------------------------------------------------
# policy 是**幂等覆盖**的，与上面的密钥相反：这里没有「已存在就跳过」。
# 理由正相反 —— policy 文件是仓库里的真相源，每次跑都应该把 Vault 里的
# 对齐到文件的内容，否则一次手工 `vault policy write` 的临时放宽会永久留在生产里。
write_policy() {
    _name="$1"
    _file="${POLICY_DIR}/${_name}.hcl"

    if [ ! -f "$_file" ]; then
        echo "✗ 找不到 policy 文件：${_file}" >&2
        exit 1
    fi

    # jq -Rs 把整个文件读成一个 JSON 字符串（转义引号与换行）。
    _payload="$(jq -Rs '{policy: .}' <"$_file")"
    must_write "写入 policy ${_name}" "sys/policies/acl/${_name}" "$_payload"
    echo "✓ 已写入 policy ${_name}。"
}

write_policy ncards-app
write_policy ncards-ops

# ----------------------------------------------------------------------------
# 5. AppRole（§3.3 / §7.4）
# ----------------------------------------------------------------------------
if exists "sys/auth/approle"; then
    echo "✓ approle auth 已启用，跳过。"
else
    must_write '启用 approle auth' 'sys/auth/approle' '{"type":"approle"}'
    echo "✓ 已启用 approle auth。"
fi

# token_ttl=1h：§3.3 与 T-005 任务卡的「token TTL 1h 自动续期」。
#   进程在剩余 10 分钟时调 auth/token/renew-self（AppRoleTokenProvider）。
# token_max_ttl=24h：续期的总上限。到顶后必须重新 login —— 这是刻意的，
#   它保证一个被偷走的 token 最多活 24 小时，而不是被无限续下去。
#
# ⚠️ secret_id_ttl=0（不过期）与 §7.4 字面的「secret_id TTL 24h 自动续期」不一致。
# 这是一处**已知且刻意**的偏差，记录在 ADR-0004 的 Consequences 里：
# 24h 的 secret_id 需要一套自动投递机制（Vault Agent 或 response wrapping）才能用，
# 而 T-005 不交付那个 —— 配上就等于让服务在部署 24 小时后集体认证失败。
# 当前 secret_id 由 sops(age) 加密后随 Ansible 下发（T-012），轮换是运维动作。
# 正解是 Vault Agent，归 T-406。
must_write '配置 AppRole ncards-app' 'auth/approle/role/ncards-app' \
    '{"token_policies":["ncards-app"],"token_ttl":"1h","token_max_ttl":"24h","secret_id_ttl":0,"secret_id_num_uses":0,"token_no_default_policy":true}'
echo "✓ 已配置 AppRole ncards-app（token_ttl=1h / max=24h）。"

# ----------------------------------------------------------------------------
# 6. 输出 role_id
# ----------------------------------------------------------------------------
# role_id 不是秘密（单独拿着它登录不了），可以打印。
# secret_id **是**秘密，所以这里**不**自动生成 —— 需要时手工执行下面提示的命令。
_role_response="$(api GET 'auth/approle/role/ncards-app/role-id')"
_role_id="$(body_of "$_role_response" | jq -r '.data.role_id // empty')"

if [ -n "$_role_id" ]; then
    echo
    echo "  VAULT_ROLE_ID=${_role_id}"
    echo
    echo "  需要 secret_id 时（⚠️ 是凭据，绝不入库、绝不进 CI 日志）："
    echo "    curl -sS -X POST -H \"X-Vault-Token: \$VAULT_TOKEN\" \\"
    echo "      ${VAULT_ADDR}/v1/auth/approle/role/ncards-app/secret-id | jq -r .data.secret_id"
fi

echo
echo "✓ Vault 初始化完成。"
