package de.ncards.core.crypto

/**
 * 包裹密钥已经不可用了 —— 用它加密过的东西**永远**解不开。
 *
 * 现实中的触发原因：系统还原到新设备、用户改动锁屏凭据（部分 ROM 会连带失效
 * 非 user-auth 绑定的密钥）、Keystore 数据损坏、厂商 ROM 的清理行为。
 *
 * 调用方唯一正确的反应是**重来一遍**（清掉旧密文、生成新密钥），不是重试，
 * 也不是把它抛给 UI —— 解不开就是解不开，重试一万次也一样。
 * [DbPassphraseProvider] 的恢复路径就是这条规则的落地点。
 */
class KeyMaterialUnrecoverableException(
    message: String,
    cause: Throwable? = null,
) : Exception(message, cause)
