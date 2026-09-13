package de.ncards.core.model.card

import java.util.Locale

/**
 * 钱包搜索的匹配规则（T-153）。
 *
 * ============================================================================
 * ⚠️ 为什么搜索是 Kotlin 而不是 SQL
 * ============================================================================
 * 直觉上这该是 `CardDao` 里一句 `WHERE title LIKE '%' || :q || '%'`。三条理由否掉它：
 *
 * 1. **SQLite 的 `LIKE` 对德语是错的。** 它内建的大小写折叠**只覆盖 ASCII**
 *    （`sqlite3_strnicmp` 的行为，除非编译期带 ICU —— SQLCipher 的 Android
 *    产物没有）。于是 `Ä` 匹配不到 `ä`、`Ö` 匹配不到 `ö`。而本库的 schema 里
 *    **一个 `COLLATE NOCASE` 都没有**，连 ASCII 那一半都不是自动的。
 *    给一个德国市场的 App 写一个匹配不了变音符号的搜索，是把 bug 写进地基。
 * 2. **数据量够不着。** §7.5 每用户上限 500 张卡，规格自己注明「P99 < 30」。
 * 3. **加 `LIKE` 要把 `observeWallet` 那段 SQL 复制第二份**（它有两个 JOIN
 *    和一个派生 `sync_state` 的子查询）。两份之间迟早漂移，而漂移时没人知道
 *    该信哪个 —— 这正是 T-009 拒绝给 `cards` 加 `sync_state` 列时用的同一条理由。
 *
 * ============================================================================
 * ⚠️ 这不是好友搜索
 * ============================================================================
 * §3.8 / C4 规定「搜索**不作输入提示**，禁止 typeahead / 自动补全 / 边输边搜，
 * 客户端只能在用户显式点击「搜索」后发起一次请求」。
 * **那条只管好友的 username 搜索** —— 它是一个账号枚举面，每一次击键都是一次
 * 对服务端的探测。
 *
 * 本文件是**本机**卡片的过滤：不发请求、不触网、数据本来就全在用户自己手里。
 * 边输边过滤在这里是对的，也是用户预期的。别把那条规则套过来。
 */
object CardSearch {
    /**
     * 归一化：`trim` + 按**德语**小写。
     *
     * ⚠️ 用 [Locale.GERMAN] 而不是 [Locale.ROOT]，也不是默认 locale：
     *
     * - 默认 locale 会让同一份数据在土耳其语设备上匹配不到 ——
     *   土耳其语的 `I` 小写是 `ı`（无点），于是 `"ITF"` 变成 `"ıtf"`，
     *   搜 `"itf"` 就搜不到了。这是 JDK 里最经典的一个 locale 陷阱。
     * - 主要市场是德国（§11.1：默认资源目录放德语），所以显式钉死德语，
     *   而不是钉死一个「谁都不是」的 ROOT。
     *
     * 德语的 `ß`：JVM 的 `lowercase` 不会把 `ß` 变成 `ss`，所以搜 `"strasse"`
     * **匹配不到** `"Straße"`。这是已知的、刻意未处理的 ——
     * 真要处理得引 ICU 的折叠表，而那是一个 for 了一个边角情形的新依赖。
     * 用户搜 `"stra"` 仍然能找到。
     */
    fun normalize(raw: String): String = raw.trim().lowercase(Locale.GERMAN)
}

/**
 * 这张卡匹不匹配搜索词。
 *
 * 空白查询**匹配一切** —— 调用方因此不需要写「查询为空就别过滤」的分支，
 * 而那个分支正是最容易忘的地方。
 *
 * 搜 [Card.title] 与 [Card.merchantLabel] 两个字段：
 * 用户记得住的是「REWE」或者他自己起的名字，两者都得能搜到。
 * **不搜** [Card.note] 与 [Card.barcodeValue] —— 备注可能很长（≤ 2000 字）而且
 * 内容与「找哪张卡」无关，码值则是一串数字，搜它只会误命中。
 */
fun Card.matches(query: String): Boolean {
    val needle = CardSearch.normalize(query)
    if (needle.isEmpty()) return true

    return CardSearch.normalize(title).contains(needle) ||
        merchantLabel?.let { CardSearch.normalize(it).contains(needle) } == true
}
