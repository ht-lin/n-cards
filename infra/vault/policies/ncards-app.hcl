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
#   keys/*        不可读密钥。授予了的话，拿到 token 就能导出密钥材料，
#                 于是「Vault 内部密钥永不出 Vault」（§5.3）这句话不成立，
#                 攻击者可以离线解密全部备份 —— 从「拿到主机」升级成「永久拿到全部数据」。
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

# ---------------------------------------------------------------- Token
# token TTL 1h，进程在剩余 10 分钟时续期（§3.3 / AppRoleTokenProvider）。
# 没有这一条，每小时都要重新走一次 AppRole login。
path "auth/token/renew-self" {
  capabilities = ["update"]
}
