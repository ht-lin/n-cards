# ncards-app —— 应用运行时的 policy（§17.4 / §7.4 / T-005）。
#
# 绑在 AppRole `ncards-app` 上，由后端进程通过 role_id + secret_id 登录取得。
# 对应的 PHP 侧是 Shared\Infrastructure\Vault\AppRoleTokenProvider。
#
# ============================================================================
# 这份清单的价值在于**它没有什么**
# ============================================================================
# §3.3 把话说得很直白：服务端加密**不防**「应用主机被完全控制」。攻击者拿到 RCE
# 就能用这个 AppRole 调 Vault 解密。既然挡不住，能做的就是把那把钥匙能开的门减到最少 ——
# 下面每一条「显式不授予」都是在缩小那次事故的爆炸半径。
#
#   keys/*        不可读 **Transit** 密钥。授予了的话，拿到 token 就能导出密钥材料，
#                 于是「Vault 内部密钥永不出 Vault」（§5.3）这句话不成立，
#                 攻击者可以离线解密全部备份 —— 从「拿到主机」升级成「永久拿到全部数据」。
#                 （JWT 的签名私钥是这条规则唯一的例外，理由与代价见下面 KV 那一节。）
#   rotate        轮换是运维动作。授予了的话，一次误操作或一次入侵就能给 ncards-hmac
#                 转个版本，而那会让**全部** email_hash 查找失效（见 CryptoKey::isRotatable()）。
#   rewrap        重加密全库的能力。归 ncards-ops（见 ncards-ops.hcl），
#                 T-404 的 RewrapCardSecrets 用那个身份跑。
#   sys/*         包括 sys/seal。授予了就等于给了「一条命令让服务全挂」的按钮。
#
# ⚠️ 往这份文件里加路径前，先问「RCE 之后这条路径会让攻击者多做什么」。
# 加了就要在 PR 里写明这个问题的答案。
#
# ============================================================================
# 为什么没有 sys/health
# ============================================================================
# `sys/health` 是**免认证**的，不需要 policy。VaultHealthCheck 打的就是它。
#
# 也刻意没有 `auth/token/lookup-self`：那会让就绪探针能验证 token 是否还有效，
# 听起来有用，但代价是给这份清单开一个口子。取舍与后果写在
# VaultHealthCheck 的类注释与 docs/runbooks/vault-unseal.md 的「升级路径」一节。

# ---------------------------------------------------------------- Transit
# capabilities 只有 "update"：Transit 的 encrypt/decrypt/hmac 全部是 POST 语义，
# Vault 把 POST 映射成 update。**不要**顺手加 "read" —— 对 transit/keys/* 而言
# read 就是读密钥元数据，而那正是上面第一条要挡的东西。

# 卡 payload 与 note（cards.barcode_value_encrypted / note_encrypted）
path "transit/encrypt/ncards-card" {
  capabilities = ["update"]
}
path "transit/decrypt/ncards-card" {
  capabilities = ["update"]
}

# PII（users.email_encrypted）
path "transit/encrypt/ncards-pii" {
  capabilities = ["update"]
}
path "transit/decrypt/ncards-pii" {
  capabilities = ["update"]
}

# email_hash / code_hash / fingerprint 的 pepper（§3.8）
path "transit/hmac/ncards-hmac" {
  capabilities = ["update"]
}
# verify 目前没有调用方 —— HmacHasher::verify() 在本地用 hash_equals 比，
# 少一次往返。保留这条是 §17.4 的原样，且将来真要用时不必改 policy。
path "transit/verify/ncards-hmac" {
  capabilities = ["update"]
}

# ---------------------------------------------------------------- JWT 签名密钥
# §5.3 密钥清单第四行：Access Token 的 Ed25519 私钥，存 KV v2。
# 读取侧是 Shared\Infrastructure\Token\VaultKvSigningKeyProvider（T-104）。
#
# ============================================================================
# ⚠️ 这是这份清单里唯一一条**让密钥材料离开 Vault** 的路径
# ============================================================================
# 别的密钥都只出借能力：Transit 的 encrypt/decrypt/hmac 在 Vault 内部完成，
# 应用永远拿不到字节（§5.3「Vault 内部密钥永不出 Vault」）。
# 这一条是例外，因为 Transit 不提供 EdDSA 的分离签名，JWT 的签名必须在应用进程里做。
#
# 按本文件抬头的规矩，回答「RCE 之后这条路径让攻击者多做什么」：
#
#   **多的是「离线伪造任意用户的 access token」，而且驱离之后仍然可以。**
#
#   注意增量在哪里。RCE 当下攻击者已经握有 transit/decrypt/*，能解开全部卡片与
#   邮箱密文 —— 数据本身在那一刻就已经失守，这条路径不改变那个结论。
#   它改变的是**时间维度**：Transit 的能力随 token 失效、随 AppRole 撤销而消失，
#   而一把被复制走的签名私钥不会。没有这条认识，事故响应会漏掉最关键的一步。
#
#   因此缓解措施是流程性的，写在 T-404 的 key-rotation runbook 里：
#   **任何一次应用主机失陷的响应，必须包含一次 JWT 签名密钥轮换**
#   （§5.3 的 6 个月周期是常规轮换，与这一条无关）。
#   轮换会踢掉全部在线会话，那在事故响应里正是期望行为。
#
# 三处刻意的收窄：
#   - 路径写死到 `current` 这一条，**不给** `secret/data/ncards/jwt/*`，
#     更不给 `secret/data/*`。将来轮换引入 `previous` 时再显式加一行 ——
#     那时应该顺便问一次「验签方真的需要读私钥吗」（不需要，它只要公钥）。
#   - 只给 `read`，不给 `create` / `update` / `delete`。应用不写密钥；
#     写入只发生在 bootstrap.sh（root）与 T-404 的运维流程（ncards-ops）。
#   - **不给** `secret/metadata/ncards/jwt/*`。那是版本列表与删除能力，
#     读取侧用不到，而它能让攻击者枚举并读取历史版本 —— 也就是把
#     「拿到当前密钥」升级成「拿到全部曾经用过的密钥」。
path "secret/data/ncards/jwt/current" {
  capabilities = ["read"]
}

# ---------------------------------------------------------------- Token
# token TTL 1h，进程在剩余 10 分钟时续期（§3.3 / AppRoleTokenProvider）。
# 没有这一条，每小时都要重新走一次 AppRole login。
path "auth/token/renew-self" {
  capabilities = ["update"]
}
