package de.ncards.data.auth

import de.ncards.core.network.api.model.Device

/**
 * 登录请求里那个 `device` 对象的来源（§6.2）。
 *
 * 抽成接口的唯一理由是**可测**：实现要 `Context`、`Build.MODEL` 与
 * `PackageManager`，三样在 JVM 单测里都没有，而 `:data:auth` 在 70% 覆盖率
 * 门禁内（`Coverage.kt` 的豁免表里没有它）。这与 `core:crypto` 把 Keystore
 * 挡在 `SecretStore` 后面是同一个做法 —— 那个模块**没有**这么做的部分
 * （`KeystoreAesGcmKeyWrapper`）最后只能整个豁免掉，实测 27.2%。
 */
internal interface DeviceDescriptorProvider {
    fun current(): Device
}
