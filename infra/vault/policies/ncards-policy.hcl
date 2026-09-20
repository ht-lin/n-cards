# ncards-policy —— **只用来下发和对账 policy 本身**的 policy（T-114 / §17.4）。
#
# 绑在 AppRole `ncards-policy` 上（bootstrap.sh 第 5 节），由 ansible 的
# vault-policy 任务在一个跑完就退出的容器里使用一次。执行体是
# infra/vault/policy-sync.sh。
#
# ============================================================================
# 缘起
# ============================================================================
# `bootstrap.sh` 写 policy 是**幂等覆盖**的，注释里写着「policy 文件是仓库里的
# 真相源」。那条纪律对，但它在长期运行的环境上**只跑过首启那一次** ——
# prod overlay 把 vault-init 关了（T-005 的正确决定），ansible 不碰 Vault
# （ADR-0004），而 CI 每次都在自己那个临时 Vault 上重跑，所以 CI 里永远是最新的。
# 于是 2026-09-19：staging 的 ncards-app 停在 9-04 那版，缺 T-104 在 9-07 加的
# `secret/data/ncards/jwt/current`，`POST /auth/otp/verify` 503，
# 而 `/health/ready` 与冒烟测试全绿。这份 policy 就是补上那个执行者的最小授权。
#
# ============================================================================
# 为什么是单独一份，而不是往 ncards-ops 上加两行
# ============================================================================
# T-114 的任务卡原话是「给 ncards-ops.hcl 加这条路径」，先那样落地过，
# 然后拆了出来。理由是**权限外溢**：`ncards-ops` 持有
# `transit/keys/+/rotate`，而那个通配符覆盖到 `ncards-hmac` ——
# 轮换它会让全部 `email_hash` 查找失效，且 HMAC 单向、**不可补救**
# （见 CryptoKey::isRotatable()）。
#
# 把两条 `sys/policies/acl/…` 挂在 ncards-ops 上，等于让**每次部署都要用到的**
# 那份凭据连带握着一条「能毁掉全部账号查找」的命令，而本文件需要的只有
# 「读写 policy」。两个身份的使用节奏也完全不同：
#
#   ncards-ops     T-404 的轮换与 rewrap，一年用几次，人工触发
#   ncards-policy  每一次部署，自动，凭据长期躺在 sops 与部署主机的进程环境里
#
# 把它们分开之后，部署流水线失陷的爆炸半径里**没有任何 transit 能力**。
#
# ============================================================================
# 按 ncards-app.hcl 抬头的规矩：RCE 之后这些路径让攻击者多做什么
# ============================================================================
#   **多的是「给 ncards-app 加一条 transit/export/encryption-key/*，再用同一台
#   主机 .env 里那份 VAULT_SECRET_ID 把三把 transit key 导出去」** ——
#   也就是把「拿到主机」升级成「永久拿到全部备份的明文」，
#   正是 ncards-app.hcl 第一条「keys/* 不可读」要挡的那个终局。
#
#   增量是真实的，不粉饰。接受它的理由是另一侧的代价更高：不给这条路径，
#   每改一行 policy 都要一次 `vault operator generate-root`（3 位 unseal key
#   持有人到场），于是 policy 实际上永远不会被下发 —— 那不是假设，
#   那就是本卡的故障本身。
#
#   收窄做了五处，缺哪一处这个取舍都不成立：
#     1. **这一份独立的 policy**，不复用 ncards-ops，于是身份里没有任何 transit；
#     2. **逐条路径，不给 `sys/policies/acl/*` 通配** —— 将来新增 policy 要显式加行；
#     3. **自己这份只给 read**（见下），杜绝自提权；
#     4. 没有 `delete`、没有 `list` —— 对账只需要逐条 read，
#        而删 policy 从来不是自动化该做的事；
#     5. 这个身份 token_ttl=10m 且**刻意没有** `auth/token/renew-self`
#        （见 bootstrap.sh 第 5 节），续不了期的短 token 是最省事的时间限制。

# ---------------------------------------------------------------- 可下发的
# 应用运行时的最小权限清单。它**最常变**（T-104 / T-105 都动过它），
# 也正是 2026-09-19 漂移的那一份。
path "sys/policies/acl/ncards-app" {
  capabilities = ["read", "create", "update"]
}

# 轮换与 rewrap 的清单。今天还没有使用方（T-404 会建），但它同样会随卡演进，
# 同样需要真的到得了长期运行的环境 —— 否则 T-404 开工那天会撞上一模一样的坑。
path "sys/policies/acl/ncards-ops" {
  capabilities = ["read", "create", "update"]
}

# ---------------------------------------------------------------- 只可读的
# ⚠️⚠️ **自己这份只给 read，绝不给 update。这一行是上面整段取舍的支点。**
#
# 有 update 就能把自己改写成 `path "*" { capabilities = [..., "sudo"] }`，
# 于是上面五处收窄会被**一次请求**全部抹掉 —— 能改自己的 policy 就等于没有 policy。
#
# 代价是真实的、也是刻意的：改这个文件本身**不能**由部署流水线下发，
# 要一次 `vault operator generate-root`（3 位 unseal key 持有人到场）。
# policy-sync.sh 对这种情况的处置是「这一份不写，但照样对账」，
# 真漂移了会红并指向 docs/runbooks/staging-first-boot.md 的
# 「已经首启过的环境怎么补上」。
path "sys/policies/acl/ncards-policy" {
  capabilities = ["read"]
}
