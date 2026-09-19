#!/usr/bin/env sh
#
# Vault 初始化：Transit 引擎、三把 key、JWT 的 KV、三份 policy、两个 AppRole（T-005 / T-114 / §5.3 / §17.4）。
#
# HTTP 助手（api / status_of / body_of / require / wait_for_vault）与
# 「为什么用 curl + HTTP API 而不是 vault CLI」的论证都在 _vault_api.sh 里 ——
# T-114 的 policy-sync.sh 要用同一套，抄第二份必然漂。
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
VAULT_DIR="$(cd "$(dirname "$0")" && pwd)"
POLICY_DIR="${VAULT_DIR}/policies"

# JWT 密钥在 KV 里的位置。T-104 的 TokenIssuer 从这里读。
JWT_KV_PATH="ncards/jwt/current"

# shellcheck source=infra/vault/_vault_api.sh
. "${VAULT_DIR}/_vault_api.sh"

require curl
require jq
require openssl

if [ -z "${VAULT_TOKEN:-}" ]; then
    echo "✗ 需要 VAULT_TOKEN（root 或具备 sys/mounts、sys/policy、sys/auth 权限的 token）。" >&2
    exit 1
fi

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

# 某个路径是否已存在。2xx = 存在，404 = 不存在，**其余一律中止**。
#
# ⚠️ 「其余一律中止」是这个函数的全部重点，别把它简化回 `2*) 0 ;; *) 1`。
# 那种写法会把 403（token 权限不够）、5xx（Vault 内部错）、以及 curl 连不上时的
# 000 全都读成「还没建」，于是调用方的**创建**分支照常执行。
# 最坏的一条是下面第 3 节的 JWT 签名密钥：生产上按 runbook 在 unseal 之后跑这个
# 脚本时，一次瞬时 5xx 就足以让它重新生成一把 Ed25519 并覆盖线上那把，
# 全部已签发的 access token 立刻不可验签（§7.1）—— 而脚本会打印「✓ 已生成」。
# 探测不出结论时停下来让人看，比猜一个「不存在」便宜得多。
#
# 只能用于「不存在 = 404」的端点，也就是 transit/keys/<name> 与 secret/data/<path>
# （已在 Vault 1.18 上实测）。sys/mounts/<path> 与 sys/auth/<path> **不是** ——
# 它们对未挂载的路径回的是 400（`No secret engine mount at transit/`），
# 与真正的参数错误分不开，所以那两类走下面的 mounted()。
exists() {
    _response="$(api GET "$1")"
    _status="$(status_of "$_response")"

    case "$_status" in
        2*) return 0 ;;
        404) return 1 ;;
        *)
            echo "✗ 探测 $1 是否存在时返回 HTTP ${_status} —— 判断不了，中止。" >&2
            echo "  只有 404 才算「不存在」。当前状态更像是权限、Vault 故障或网络问题；" >&2
            echo "  此时继续跑下去会覆盖已存在的密钥材料。" >&2
            echo "  Vault 响应：$(body_of "$_response")" >&2
            exit 1
            ;;
    esac
}

# 某个 secrets engine / auth method 是否已挂载。
#
# 走**列表**端点（GET sys/mounts、GET sys/auth）再用 jq 查键，而不是逐个 GET
# sys/mounts/<path>：后者对「未挂载」回 400 而不是 404（见 exists() 的注释），
# 于是「不存在」和「请求有问题」在状态码上是同一个，没法安全区分。
# 列表端点只有一种成功形态，非 2xx 一律是故障，判断是干净的。
#
#   $1 列表路径：sys/mounts | sys/auth
#   $2 要找的键，带尾斜杠：transit/ | secret/ | approle/
mounted() {
    _list_path="$1"
    _key="$2"

    _response="$(api GET "$_list_path")"
    _status="$(status_of "$_response")"

    case "$_status" in
        2*) ;;
        *)
            echo "✗ 读取 ${_list_path} 失败（HTTP ${_status}）：$(body_of "$_response")" >&2
            exit 1
            ;;
    esac

    # -e：查到返回 0，没查到返回 1，正好当布尔用。
    body_of "$_response" | jq -e --arg k "$_key" '(.data // .) | has($k)' >/dev/null
}

# ----------------------------------------------------------------------------
# 0. 等 Vault 就绪
# ----------------------------------------------------------------------------
# 封印与未初始化对**本脚本**都是致命的：没解封就一步都做不了。
# （policy-sync.sh 对同样两档的处置相反 —— 它跳过，见那里的注释。）
wait_for_vault || case "$?" in
    2)
        echo "✗ Vault 处于封印状态。请先人工 unseal（docs/runbooks/vault-unseal.md）。" >&2
        exit 1
        ;;
    3)
        echo "✗ Vault 未初始化。生产请先按 docs/runbooks/vault-unseal.md 初始化并 unseal。" >&2
        exit 1
        ;;
    *) exit 1 ;;
esac

# ----------------------------------------------------------------------------
# 1. Transit 引擎
# ----------------------------------------------------------------------------
if mounted 'sys/mounts' 'transit/'; then
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
if mounted 'sys/mounts' 'secret/'; then
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
write_policy ncards-policy

# ⚠️ T-114：这一段**只在首启与 generate-root 仪式上跑得到**，而 `*.hcl` 会随
# 每张卡演进。长期运行的环境上把 Vault 对齐到这三个文件的执行者是
# policy-sync.sh（由 ansible 的 vault-policy 任务每次部署调用），不是本脚本。
# 少了那个执行者的后果就是 T-114 的缘起：staging 的 ncards-app 停在 9-04 那版，
# 而 T-104 在 9-07 给它加了 JWT 那条路径，于是登录整个不通而所有绿灯都是绿的。

# ----------------------------------------------------------------------------
# 5. AppRole（§3.3 / §7.4）
# ----------------------------------------------------------------------------
if mounted 'sys/auth' 'approle/'; then
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

# T-114：policy 下发与对账的身份。**不是**应用用的，也不进 app/worker 的环境 ——
# 它由 ansible 的 vault-policy 任务在一个跑完就退出的容器里用一次（见
# infra/compose/docker-compose.base.yml 的 vault-policy 服务）。
#
# ⚠️ 绑的是 `ncards-policy` 而**不是** `ncards-ops`，别图省事合并。
# ncards-ops 持有 transit/keys/+/rotate，而那个通配符覆盖到 ncards-hmac ——
# 轮换它会让全部 email_hash 查找失效且不可补救。这份凭据**每次部署都要用**、
# 长期躺在 sops 与部署主机的进程环境里，不该连带握着那条命令。
# 完整论证在 policies/ncards-policy.hcl 抬头。
#
# token_ttl=10m / max=30m：这个身份只服务一条几秒钟的命令。而且
# ncards-policy.hcl **刻意没有** auth/token/renew-self —— 续不了期的短 token
# 是这条路径上最省事的一道时间限制，泄露一枚也只值 10 分钟。
#
# ⚠️ 与 ncards-app 一样 secret_id_ttl=0（不过期），理由同上面那段（ADR-0004）。
must_write '配置 AppRole ncards-policy' 'auth/approle/role/ncards-policy' \
    '{"token_policies":["ncards-policy"],"token_ttl":"10m","token_max_ttl":"30m","secret_id_ttl":0,"secret_id_num_uses":0,"token_no_default_policy":true}'
echo "✓ 已配置 AppRole ncards-policy（token_ttl=10m / max=30m，T-114 的 policy 下发身份）。"

# ----------------------------------------------------------------------------
# 6. 输出 role_id
# ----------------------------------------------------------------------------
# role_id 不是秘密（单独拿着它登录不了），可以打印。
# secret_id **是**秘密，所以这里**不**自动生成 —— 需要时手工执行下面提示的命令。
#
# ⚠️ 提示里给的是 `docker compose exec`，而不是本脚本自己在用的那种 curl。
# 因为 ${VAULT_ADDR} 是**容器视角**的地址：`vault` 这个名字只在 compose 的
# ncards_backing 网络里解析得出来（那个网络 internal: true，vault 也没有 ports:）。
# 打印一条带 http://vault:8200 的 curl，读的人几乎一定是在宿主机 shell 里粘贴它，
# 于是撞上 `Could not resolve host: vault` —— 一条跟权限、跟 Vault 都无关的错。
print_role_id() {
    _role="$1"
    _var="$2"

    _role_response="$(api GET "auth/approle/role/${_role}/role-id")"
    _role_id="$(body_of "$_role_response" | jq -r '.data.role_id // empty')"

    [ -n "$_role_id" ] || return 0

    echo
    echo "  ${_var}=${_role_id}"
    echo
    echo "  对应的 secret_id（⚠️ 是凭据，绝不入库、绝不进 CI 日志）："
    echo
    echo "    docker compose exec -e VAULT_TOKEN=\"\$VAULT_TOKEN\" vault \\"
    echo "      vault write -f -field=secret_id auth/approle/role/${_role}/secret-id"
}

echo
echo "  在宿主机 /opt/ncards 下，COMPOSE_FILE 已导出、VAULT_TOKEN 还在环境里，"
echo "  下面两对值都要拿，都写进 group_vars/<环境>/secrets.sops.yaml："

print_role_id ncards-app VAULT_ROLE_ID
# ⚠️ T-114：policy 这一对**不进 .env**（env.j2 里没有它们）。.env 是 app 与
# worker 容器的环境来源，把「改写 ncards-app policy」的能力放进那两个容器的
# `docker inspect` 正好抵消掉 ncards-app.hcl 整份清单的意义。
# 它只被 ansible 的 vault-policy 任务从进程环境传进一个一次性容器。
print_role_id ncards-policy VAULT_POLICY_ROLE_ID

echo
echo "  两对都拿到之后再吊销 root token（顺序反了就得走 operator generate-root）："
echo
echo "    docker compose exec -e VAULT_TOKEN=\"\$VAULT_TOKEN\" vault vault token revoke -self"

echo
echo "✓ Vault 初始化完成。"
