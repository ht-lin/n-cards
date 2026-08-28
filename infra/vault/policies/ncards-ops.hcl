# ncards-ops —— 密钥轮换与 rewrap 的 policy（§5.3 轮换流程 / §17.4 / T-005）。
#
# ============================================================================
# 为什么这些权限不给应用
# ============================================================================
# T-005 的任务卡把 policy 概括成「仅对指定 key 的 encrypt|decrypt|rewrap」，
# 但 §17.4 给出的**具体策略文本**里根本没有 rewrap 路径，且写明
# 「显式不授予：keys/*（不可读密钥）、rotate、rewrap（由独立的 ncards-ops policy 持有）」。
# 以 §17.4 为准，理由见 ncards-app.hcl 顶部那段：应用的 AppRole 是 RCE 之后
# 攻击者会拿到的东西，rewrap 意味着「能重加密全库」，不该在那把钥匙上。
#
# ============================================================================
# 谁用它
# ============================================================================
# T-005 **不**交付这个身份的使用方。它现在的作用是把「轮换权限归谁」这件事
# 在 policy 层面先定下来，免得 T-404 到时候图省事直接往 ncards-app 上加。
#
# T-404（密钥轮换与 rewrap）会：
#   - 用这个 policy 建一个独立的 AppRole 或人工 token
#   - 让 Messenger 任务 RewrapCardSecrets 以该身份分批调 rewrap（§5.3 轮换流程第 3 步）
#   - 写 docs/runbooks/key-rotation.md
#
# ⚠️ ncards-hmac **绝不轮换**。下面的通配符在语法上覆盖到了它，
# 但那是运维纪律要挡的事，不是 policy 能表达的
# （见 Shared\Domain\Crypto\CryptoKey::isRotatable() 的注释：
#  轮换它会让全部 email_hash 查找失效，且 HMAC 单向不可补救）。
# key-rotation.md 必须把这一条写在最前面。

# 轮换：产生新版本（§5.3 轮换流程第 1 步）
path "transit/keys/+/rotate" {
  capabilities = ["update"]
}

# rewrap：无需解密即可把密文换到新版本（第 3 步）。
# 这是 Transit 相对「应用层自管 DEK」的核心优势（§5.3），
# 因为它意味着重加密全库的过程中明文一次都不会到应用侧。
path "transit/rewrap/+" {
  capabilities = ["update"]
}

# 读 key 元数据：拿 latest_version 与 min_decryption_version，
# 用来判断 rewrap 是否已全部完成（第 4 步的前置条件）。
#
# ⚠️ 这是 read 而不是 update，**读不到密钥材料本身** —— Transit 的
# transit/keys/<name> 只返回版本号与创建时间，密钥材料永不出 Vault（§5.3）。
# 即便如此也没给 ncards-app：应用没有任何需要知道 key 版本的场景。
path "transit/keys/+" {
  capabilities = ["read"]
}

# 提升 min_decryption_version（第 4 步）。全部行 rewrap 完成后执行，
# 之后旧版本密文将无法解密 —— 顺序错了会造成数据不可读，runbook 必须写清。
path "transit/keys/+/config" {
  capabilities = ["update"]
}
