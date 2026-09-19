#!/usr/bin/env sh
#
# Vault policy 的下发执行者与漂移检测（T-114 / §17.4 / ADR-0004）。
#
# ============================================================================
# 这个脚本存在的理由
# ============================================================================
# `bootstrap.sh` 的第 4 节写 policy 时是**幂等覆盖**的，注释里写着「policy 文件是
# 仓库里的真相源，每次跑都应该把 Vault 里的对齐到文件的内容」。那条纪律是对的，
# 但在长期运行的环境上**没有任何执行者**，三件各自合理的事叠出了这个洞：
#
#   - docker-compose.prod.yml 把 vault-init 设成 profiles: ["disabled"]（T-005）
#   - ansible 的 deploy.yml / site.yml 不碰 Vault（ADR-0004）
#   - CI 每次都在自己那个临时 Vault 上重跑 bootstrap.sh，所以 CI 里永远是最新的
#
# 于是 2026-09-19：staging 的 ncards-app 停在 9-04 那版，而
# `secret/data/ncards/jwt/current` 是 9-07 才随 T-104 进 ncards-app.hcl 的 ——
# `POST /auth/otp/verify` 返回 503「The JWT signing key could not be read.」，
# 而 /health/ready 与冒烟测试全绿（前者打的是免认证的 sys/health，
# 后者一个 /v1 端点都不碰）。
#
# ============================================================================
# ⚠️ 只碰 policy，一行都不碰 KV 与 transit
# ============================================================================
# 把整个 bootstrap.sh 塞进部署是更省事的写法，代价是连同它的风险一起继承：
# 那里的密钥材料那几段是「已存在就跳过」，一旦哪次探测返回了 5xx 被误读成
# 「不存在」，就会覆盖掉 JWT 签名密钥 = 全部在线 access token 立刻失效（§7.1）。
# 本脚本没有任何写 KV 的代码路径，所以那个风险在这里结构上不存在。
# 它也因此**不需要 root token**，见下面的认证一节。
#
# ============================================================================
# 用法
# ============================================================================
#   policy-sync.sh check    # 只对账，不改 Vault（默认）
#   policy-sync.sh push     # 覆盖下发，然后自动再对一次账
#
# 部署时由 ansible 调用（infra/ansible/roles/ncards_stack/tasks/vault_policy.yml），
# 跑在 compose 的一次性容器 vault-policy 里 —— backing 网络 internal: true、
# vault 没有 ports:，从主机 shell 直接 curl 是连不上的。
#
# 本地/CI 手动：
#   VAULT_ADDR=http://127.0.0.1:8200 VAULT_TOKEN=dev-only-root-token ./policy-sync.sh check
#
# 依赖：curl、jq、diff。
#
# ============================================================================
# 退出码
# ============================================================================
#   0  一致（check）／已对齐（push）
#   1  漂移，或出错
#   3  Vault 封印或未初始化 —— **跳过，不是失败**
#
# ⚠️ 3 单独一档是必须的。ADR-0004 下主机每次重启后 Vault 都是封印状态，
# 那时对不了账是期望行为，不是漂移。把它并进 1 的话，每次重启后的第一次部署
# 都会红在这一步上，而真正要做的是找三个人来 unseal —— 与 healthcheck.yml
# 把 vault_sealed 与 broken 分开是同一个理由。
set -eu

MODE="${1:-check}"

case "$MODE" in
    check | push) ;;
    *)
        echo "用法: $0 [check|push]" >&2
        exit 1
        ;;
esac

VAULT_ADDR="${VAULT_ADDR:-http://127.0.0.1:8200}"
VAULT_DIR="$(cd "$(dirname "$0")" && pwd)"
POLICY_DIR="${VAULT_DIR}/policies"

# shellcheck source=infra/vault/_vault_api.sh
. "${VAULT_DIR}/_vault_api.sh"

require curl
require jq
require diff
require cmp

# ----------------------------------------------------------------------------
# 0. 等 Vault 就绪
# ----------------------------------------------------------------------------
# 与 bootstrap.sh 相反：封印/未初始化在这里**不是错误**，见文件头的退出码一节。
wait_for_vault || case "$?" in
    2)
        echo "· Vault 处于封印状态，跳过本次 policy 对账。"
        echo "  这是 ADR-0004 下主机重启后的期望状态，不是漂移。"
        echo "  解封见 docs/runbooks/vault-unseal.md；解封后下一次部署会自动对账。"
        exit 3
        ;;
    3)
        echo "· Vault 尚未初始化，跳过本次 policy 对账。"
        echo "  首启流程见 docs/runbooks/staging-first-boot.md 第 8–9 步。"
        exit 3
        ;;
    *) exit 1 ;;
esac

# ----------------------------------------------------------------------------
# 1. 认证
# ----------------------------------------------------------------------------
# 两条路，按「手边有什么」选：
#   VAULT_TOKEN      CI（dev 模式的 root token）与人工排查
#   AppRole ncards-policy  部署流水线。它**没有** root，能做的事就是
#                          ncards-policy.hcl 那三条 sys/policies/acl/…，
#                          **一条 transit 都没有**（那是它与 ncards-ops 分家的理由）
#
# ⚠️ 检测必须能跑在不持有 root token 的身份上。否则它自己就成了一个需要
# 「找三个人做一次 generate-root」的步骤，于是永远不会被定期跑 —— 一个
# 永远不跑的检测器与没有检测器是同一回事。
if [ -z "${VAULT_TOKEN:-}" ]; then
    if [ -z "${VAULT_ROLE_ID:-}" ] || [ -z "${VAULT_SECRET_ID:-}" ]; then
        echo "✗ 需要 VAULT_TOKEN，或者 VAULT_ROLE_ID + VAULT_SECRET_ID（AppRole ncards-policy）。" >&2
        echo "  部署时这两个值由 ansible 从 sops 解密后传入，见" >&2
        echo "  infra/ansible/roles/ncards_stack/tasks/vault_policy.yml。" >&2
        exit 1
    fi

    # ⚠️ 用 jq --arg 组 JSON，不手工拼字符串：secret_id 是 Vault 生成的，
    # 字符集可控，但一旦哪天换成别的来源，手拼的引号就是一个静默的认证失败。
    _login_payload="$(jq -n --arg r "$VAULT_ROLE_ID" --arg s "$VAULT_SECRET_ID" \
        '{role_id: $r, secret_id: $s}')"

    # login 是免认证端点，但 api() 会带上 X-Vault-Token 头 —— 空值也无妨。
    VAULT_TOKEN=''
    _login_response="$(api POST 'auth/approle/login' "$_login_payload")"
    _login_status="$(status_of "$_login_response")"

    case "$_login_status" in
        2*) ;;
        *)
            echo "✗ AppRole 登录失败（HTTP ${_login_status}）：$(body_of "$_login_response")" >&2
            echo "  400 invalid role or secret ID → secret_id 已失效或粘错了，重新签发一枚：" >&2
            echo "    vault write -f -field=secret_id auth/approle/role/ncards-policy/secret-id" >&2
            echo "  400 role \"ncards-policy\" does not exist → 这个环境还没跑过带 T-114 的 bootstrap.sh，" >&2
            echo "    见 docs/runbooks/staging-first-boot.md 的「已经首启过的环境怎么补上」。" >&2
            exit 1
            ;;
    esac

    VAULT_TOKEN="$(body_of "$_login_response" | jq -r '.auth.client_token // empty')"
    unset _login_payload _login_response

    if [ -z "$VAULT_TOKEN" ]; then
        echo "✗ AppRole 登录返回 2xx 但没有 client_token —— 响应结构不对，中止。" >&2
        exit 1
    fi

    echo "✓ 已以 AppRole ncards-policy 身份登录。"
fi

export VAULT_TOKEN

# ----------------------------------------------------------------------------
# 2. 下发（push）
# ----------------------------------------------------------------------------
# 与 bootstrap.sh 第 4 节同一段代码同一个语义：幂等覆盖，没有「已存在就跳过」。
#
# 返回 0 已下发 · 1 失败 · 2 当前身份没有写权限（**不是失败**，见下）
push_policy() {
    _name="$1"
    _file="${POLICY_DIR}/${_name}.hcl"

    # jq -Rs 把整个文件读成一个 JSON 字符串（转义引号与换行）。
    _payload="$(jq -Rs '{policy: .}' <"$_file")"
    _response="$(api POST "sys/policies/acl/${_name}" "$_payload")"
    _status="$(status_of "$_response")"

    case "$_status" in
        2*)
            echo "  ✓ 已下发 ${_name}"
            ;;
        403)
            # ⚠️⚠️ **403 在这里不是失败，别"修"成 return 1。**
            #
            # 以 AppRole ncards-policy 跑时，这一条**只会**发生在
            # ncards-policy 自己身上：那份 hcl 给自己那条路径**刻意只有 read**，因为有
            # update 就能把自己改写成 `path "*" { capabilities = [… "sudo"] }`。
            # 那道自提权防线是对的，而它的直接后果就是「下发时有一份写不了」。
            #
            # 把它当失败的话，**每一次部署都会红在这里**（实测过），于是这条
            # 门禁一周内就会被关掉 —— 而那正是本卡要装上的那道门禁。
            # 正确的降级是：这一份不写，但照样**对账**。真漂移了后面那轮 check
            # 会红，处置是一次 generate-root 仪式（见文件末尾的提示）。
            echo "  · ${_name}：当前身份没有写权限，本份改为只对账"
            return 2
            ;;
        *)
            echo "  ✗ 下发 ${_name} 失败（HTTP ${_status}）：$(body_of "$_response")" >&2
            return 1
            ;;
    esac
}

# ----------------------------------------------------------------------------
# 3. 对账（check）
# ----------------------------------------------------------------------------
# 逐字比较。Vault 对 ACL policy 是**原样存、原样回**的（不重排、不格式化），
# 而 bootstrap.sh 与上面的 push_policy 用 `jq -Rs` 写进去的正是文件的原始字节，
# 所以「一个字节都不差」是一个可以要求的判据 —— 也正因为严格，它才能发现
# 「有人手工 `vault policy write` 临时放宽了一条」这种只差几个字符的漂移。
#   $1 policy 名
#   $2 'quiet' 时不打印任何东西，只用退出码回答「一致吗」（push 前的探测用）
check_policy() {
    _name="$1"
    _quiet="${2:-}"
    _file="${POLICY_DIR}/${_name}.hcl"

    _response="$(api GET "sys/policies/acl/${_name}")"
    _status="$(status_of "$_response")"

    case "$_status" in
        2*) ;;
        404)
            if [ "$_quiet" = quiet ]; then return 1; fi
            echo "  ✗ ${_name}：Vault 里**根本没有**这份 policy。" >&2
            echo "    这个环境要么没跑过 bootstrap.sh，要么有人删掉了它。" >&2
            return 1
            ;;
        403)
            if [ "$_quiet" = quiet ]; then return 1; fi
            echo "  ✗ ${_name}：读被拒（403）。当前身份缺 sys/policies/acl/${_name} 的 read。" >&2
            return 1
            ;;
        *)
            if [ "$_quiet" = quiet ]; then return 1; fi
            echo "  ✗ ${_name}：读失败（HTTP ${_status}）：$(body_of "$_response")" >&2
            return 1
            ;;
    esac

    # ⚠️⚠️ **`jq -j` 而不是 `-r`，这一个字母决定了本脚本有没有用。**
    #
    # Vault 存的 policy 字符串就是文件的原始字节，**末尾那个换行也在里面**。
    # `-r` 会在输出后再补一个换行，于是落盘的 _actual 永远比仓库文件多一行空行，
    # 每一份 policy 都被判成漂移 —— 一个恒红的检测器与没有检测器是同一回事
    # （实测过：两份 policy 同时报 `@@ -123,4 +123,3 @@` 少一个空行）。
    # `-j` 是 raw + 不补换行，出来的就是 Vault 里那串字节本身。
    #
    # 也别图省事换成 `[ "$a" = "$b" ]`：`$(...)` 会**吃掉**末尾换行，于是
    # 「仓库文件末尾多/少一个换行」这种真实漂移反而被比成一致 —— 方向相反的同一个坑。
    _actual="$(mktemp)"
    body_of "$_response" | jq -j '.data.policy // ""' >"$_actual"

    # quiet 只回答「一致吗」，一个字都不打 —— push 前的探测走这条，
    # 否则每份 policy 会先打一遍 diff、跟着下发完再打一遍，读的人分不清哪个是结论。
    if [ "$_quiet" = quiet ]; then
        if cmp -s "$_actual" "$_file"; then
            rm -f "$_actual"
            return 0
        fi
        rm -f "$_actual"
        return 1
    fi

    # ⚠️ `-L` 而不是 GNU 的长选项 `--label`：这个脚本跑在 alpine 的 busybox diff 上
    # （infra/vault/Dockerfile），它只认短的那个。
    if diff -u -L "${_name}（Vault 里的）" -L "${_name}.hcl（仓库里的）" \
        "$_actual" "$_file"; then
        echo "  ✓ ${_name} 一致"
        rm -f "$_actual"
        return 0
    fi

    rm -f "$_actual"
    echo "  ✗ ${_name} 漂移 —— 上面的 diff 就是差异所在。" >&2
    return 1
}

# ----------------------------------------------------------------------------
# 4. 主流程
# ----------------------------------------------------------------------------
# policy 清单从 policies/*.hcl 现场列出来，不写死名字：将来新增一份 policy
# 不需要记得回来改这个脚本。漏改的症状会是「新 policy 永远不被对账」，
# 而那正是本卡要消灭的那一类静默。
# ⚠️ 末尾的 `|| true` 不能省。目录里一个 .hcl 都没有时 `ls` 以非 0 退出，
# 而 `VAR="$(…)"` 会把那个状态当成赋值自己的状态 —— `set -e` 于是**静默**带走
# 整个脚本，下面那段精心写的「空清单」提示一个字都打不出来。
POLICIES="$(cd "$POLICY_DIR" && ls ./*.hcl 2>/dev/null | sed 's#^\./##; s#\.hcl$##' || true)"

if [ -z "$POLICIES" ]; then
    echo "✗ ${POLICY_DIR} 下一个 .hcl 都没有 —— 挂载或同步出了问题，中止。" >&2
    echo "  空清单会让本脚本「什么都没对账」却返回 0，那比红更糟。" >&2
    exit 1
fi

failed=0
pushed=0
unwritable=''

if [ "$MODE" = push ]; then
    echo "→ 下发 policy（仅下发与仓库不一致的那些）"

    for _p in $POLICIES; do
        # ⚠️ **先探测再写，不是无条件覆盖。** 两个理由，第二个是硬的：
        #   1. ansible 的 changed 才有意义 —— 无条件写的话每次部署都报 changed，
        #      于是「这次部署真的动了 Vault 吗」永远看不出来。
        #   2. ncards-policy 那份**当前身份写不了**（它自己那条只有 read，见
        #      push_policy 的 403 分支）。稳态下它与仓库一致、根本不需要写，
        #      先探测就完全不会撞上那个 403。只有它**真的**变了才会撞 ——
        #      而那时撞上去正是我们要的信号。
        if check_policy "$_p" quiet; then
            echo "  · ${_p} 已是最新，跳过下发"
            continue
        fi

        # `set -e` 下不能直接调：非 0 返回会带走整个脚本。
        _rc=0
        push_policy "$_p" || _rc=$?

        case "$_rc" in
            0) pushed=1 ;;
            2) unwritable="${unwritable}${_p} " ;;
            *) failed=1 ;;
        esac
    done

    if [ "$failed" -ne 0 ]; then
        echo >&2
        echo "✗ policy 下发失败，见上。" >&2
        exit 1
    fi

    # ⚠️ 「已更新」这四个字是 ansible 的 changed_when 判据
    # （roles/ncards_stack/tasks/vault_policy.yml），改字面量要两边一起改。
    if [ "$pushed" -eq 1 ]; then
        echo "  已更新"
    fi
    echo
fi

# push 之后**一定**再对一次账：写成功不等于写对。最典型的是 token 只对其中
# 一份 policy 有权限，另一份静默停在旧版本上 —— 那正是本卡故障的形状。
echo "→ 对账（Vault ←→ infra/vault/policies/*.hcl 逐字）"
for _p in $POLICIES; do
    check_policy "$_p" || failed=1
done

echo

if [ "$failed" -ne 0 ]; then
    echo "✗ Vault 里的 policy 与仓库不一致。"

    if [ -n "$unwritable" ]; then
        # 唯一会走到这里的现实场景：有人改了 ncards-policy.hcl 本身。
        # 那份 policy 按设计就不能由部署身份改写，只能走 root。
        echo
        echo "  ⚠️ 其中这几份**当前身份没有写权限**，自动下发帮不上忙：${unwritable}"
        echo "  这不是配置错误，是刻意的：能改自己的 policy 就等于没有 policy。"
        echo "  改这几份要走一次 \`vault operator generate-root\`（3 位 unseal key"
        echo "  持有人到场），见 docs/runbooks/staging-first-boot.md 的"
        echo "  「已经首启过的环境怎么补上」。"
        echo
    fi

    echo "  处置：跑一次 \`policy-sync.sh push\`（部署流水线会自动做），"
    echo "  或 ansible-playbook deploy.yml --tags vault-policy。"
    echo "  若 diff 显示 Vault 那边**多**了东西，先搞清楚是谁手工放宽的再覆盖。"
    exit 1
fi

echo "✓ policy 与仓库一致。"
