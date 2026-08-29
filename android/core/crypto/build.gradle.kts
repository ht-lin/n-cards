// Keystore 包装、密钥存储、DB passphrase 供给（§3.4 / §7.3）。由 T-009 交付。
//
// 这里**没有** androidx.security:security-crypto。§3.4 的字面表述是
// 「Keystore AES-GCM 包裹后存 EncryptedSharedPreferences」，但那个库已被 Google
// 标记为 deprecated，而它在本设计里只是包裹之外的第二层容器 —— 见 ADR-0007。

plugins {
    id("ncards.android.library")
    id("ncards.android.hilt")
}

android {
    namespace = "de.ncards.core.crypto"
}

dependencies {
    implementation(libs.timber)
}
