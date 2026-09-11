package de.ncards.navigation

import android.net.Uri

/**
 * 从 `https://app.n-cards.de/l/magic/<token>` 里抠出令牌（ADR-0016 / T-151）。
 *
 * ⚠️ [TOKEN] 与 `infra/caddy/site/assets/magic.js` 里那条正则**必须逐字相同**。
 * Web 落地页那一份的注释解释了它为什么存在：
 *
 * > 这条校验不是「防攻击」——令牌本来就是给持有者用的…它防的是**把垃圾拼进
 * > 下一跳再交出去**。
 *
 * 这一侧的理由平行：`/l/magic/` 后面跟着任意内容的 URL 谁都能造（一条 adb
 * 命令就够），而那串东西会被原样放进 `POST /auth/magic/consume` 的请求体。
 * 契约给 `token` 的约束是 `minLength: 32, maxLength: 512`，先在这里挡住，
 * 省掉一次注定 400 的往返，也省掉一次白白消耗的 IP 限速额度（§7.5：60/h）。
 *
 * ⚠️ **只认 `/l/magic/`。** 同一个 `intent-filter` 的 pathPrefix 是 `/l/`，
 * 因为 `/l/devices/` 与 `/l/security/` 两个纯说明页也在那下面（T-104 / T-105
 * 的提醒信）。它们今天没有 App 侧的处置 —— 落到这里返回 null，
 * MainActivity 当作普通启动。
 */
internal object MagicLinkUri {
    private const val PREFIX = "/l/magic/"

    /** 32–512 个 base64url 字符。与落地页那份逐字相同。 */
    private val TOKEN = Regex("^[A-Za-z0-9_-]{32,512}$")

    fun tokenFrom(uri: Uri?): String? =
        uri
            ?.path
            ?.takeIf { it.startsWith(PREFIX) }
            ?.removePrefix(PREFIX)
            ?.takeIf(TOKEN::matches)
}
