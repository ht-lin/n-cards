package de.ncards.core.model.card

import de.ncards.core.model.barcode.BarcodeFormat
import java.time.LocalDate

/**
 * 一张卡**待写入的那几个字段**（T-155 的新增/编辑表单）。
 *
 * ============================================================================
 * 它与 [Card] 是两个不同的东西
 * ============================================================================
 * [Card] 是「这张卡在当前用户视角下的全部样子」—— 它带着 `role`、`sortOrder`、
 * `isPinned`、`memberCount`、`revision`、`syncState`，而**那些字段一个都不是用户能填的**：
 * 前四个来自 `card_members` 或服务端裁剪，`revision` 是服务端的乐观锁，
 * `syncState` 是从 outbox 派生的。
 *
 * 本类只有七个字段，恰好是契约 `CardUpdate` 的属性集（`docs/api/openapi.yaml`），
 * 也恰好是 §5.2 权限矩阵里「owner 可改」的那两行。少一个字段都会让表单能改到
 * 它不该改的东西，多一个则会让 `PATCH` 发出契约不接受的键。
 *
 * ============================================================================
 * ⚠️ [colorWire] 是 `String` 而不是 [CardColor]，这一条是载重的
 * ============================================================================
 * [Card.color] 的注释逐字写着这个陷阱：一个装了新版本 App 的设备发来 `mint_600`，
 * 在本版本里 [CardColor.fromWire] 把它解析成 [CardColor.BLUE]。若编辑时把**枚举**
 * 写回去，那张卡的颜色就被老客户端**静默改成了蓝色** —— 而服务端没有任何机制会发现
 * （`color` 不参与校验，`PATCH` 只比 revision）。
 *
 * 所以表单的规矩是：用户**没动**颜色 → [colorWire] 原样透传；
 * 用户**动了** → 才写 `picked.wireName`。只有后一条路才允许经过枚举。
 *
 * @property colorWire 调色板键的**原样字符串**，见上。
 * @property expiresOn `null` = 不过期（§3.13）。一期仅本地展示与排序，不做推送提醒。
 */
data class CardDraft(
    val title: String,
    val merchantLabel: String?,
    val colorWire: String,
    val barcodeFormat: BarcodeFormat,
    val barcodeValue: String,
    val note: String?,
    val expiresOn: LocalDate?,
)

/** 表单上能出问题的那几个字段。UI 按它把错误挂到对应的输入框上。 */
enum class CardField {
    TITLE,
    MERCHANT_LABEL,
    BARCODE_VALUE,
    NOTE,
}

/**
 * 一个字段**长度层面**的问题。
 *
 * ⚠️ **码值的内容**不在这里判 —— 「这串字符能不能编成一个 EAN-13」要 ZXing 才知道，
 * 而 `core:model` 是 `ncards.jvm.library`，这里连 ZXing 都 import 不到。
 * 那一半住在 `core:barcode` 的 `validateBarcodePayload`（T-152 的落地记录点名要求
 * 落点在那里，「不要在 `feature:cardedit` 里另起一套」）。
 *
 * 两半在 `feature:cardedit` 的 ViewModel 里合流。分开不是妥协，是因为它们的
 * 依赖不同：长度上限来自契约，码制规则来自编码器。
 *
 * 是 sealed 而不是字符串：§10.4 要求 ViewModel 不得 `getString`，文案由 UI 层映射。
 */
sealed interface CardDraftProblem {
    /** 必填项是空的（或只有空白）。 */
    data object Blank : CardDraftProblem

    /**
     * 超长。
     *
     * @property limit 上限值，用于文案里那句「最多 100 个字符」。
     * @property unit 上限的单位 —— 码值按**字节**算，其余按**字符**算。见 [CardLimits]。
     */
    data class TooLong(
        val limit: Int,
        val unit: LimitUnit,
    ) : CardDraftProblem

    enum class LimitUnit { CHARACTERS, BYTES }
}

/**
 * 契约与 §7.5 的那几个上限，**全仓唯一的一处**。
 *
 * 数字来自 `docs/api/openapi.yaml` 的 `CardCreate` / `CardUpdate`，
 * 与 §7.5「每用户卡数 500；barcode payload 1024 字节；note 2000 字符；title 100 字符」一致。
 */
object CardLimits {
    const val TITLE_MAX_CHARACTERS = 100
    const val MERCHANT_LABEL_MAX_CHARACTERS = 100
    const val NOTE_MAX_CHARACTERS = 2000

    /** 码值上限。⚠️ 单位是字节 —— 复用 [BarcodeFormat.MAX_PAYLOAD_BYTES]，别再写一个 1024。 */
    const val BARCODE_VALUE_MAX_BYTES = BarcodeFormat.MAX_PAYLOAD_BYTES
}

/**
 * 长度层面的逐字段校验。返回空 Map = 这一层没问题。
 *
 * ============================================================================
 * ⚠️ 两个不同的计数单位，都不是 `String.length`
 * ============================================================================
 * - **文本字段按码点算**。`String.length` 是 UTF-16 码元数，一个 emoji 是 2。
 *   契约的 `maxLength` 按 JSON Schema 的定义数的是**字符**（码点），
 *   所以用 `String.length` 会让一个「REWE 🛒」这样的标题在 50 个 emoji 处就被本机拒掉，
 *   而服务端其实收得下。[Card.initial] 已经为同一个理由用了 `offsetByCodePoints`。
 * - **码值按 UTF-8 字节算**，理由见 [BarcodeFormat.MAX_PAYLOAD_BYTES] 的注释
 *   （码值是条码的原始载荷，按字符计会让一个合法的 1024 字节 PDF417 被拒）。
 *   字节数 ≥ 码点数，所以这一条同时满足了契约的 `maxLength: 1024`。
 */
fun CardDraft.problems(): Map<CardField, CardDraftProblem> =
    buildMap {
        if (title.isBlank()) {
            put(CardField.TITLE, CardDraftProblem.Blank)
        } else if (title.characters() > CardLimits.TITLE_MAX_CHARACTERS) {
            put(CardField.TITLE, tooLongCharacters(CardLimits.TITLE_MAX_CHARACTERS))
        }

        // merchantLabel 可空且**可以为空串** —— 契约是 `[string, "null"]` 无 minLength。
        // 空串与 null 的区别由 data:card 在写库前归一（空串 → null），不在这里判。
        if ((merchantLabel?.characters() ?: 0) > CardLimits.MERCHANT_LABEL_MAX_CHARACTERS) {
            put(CardField.MERCHANT_LABEL, tooLongCharacters(CardLimits.MERCHANT_LABEL_MAX_CHARACTERS))
        }

        if (barcodeValue.isEmpty()) {
            put(CardField.BARCODE_VALUE, CardDraftProblem.Blank)
        } else if (barcodeValue.utf8Bytes() > CardLimits.BARCODE_VALUE_MAX_BYTES) {
            put(
                CardField.BARCODE_VALUE,
                CardDraftProblem.TooLong(
                    limit = CardLimits.BARCODE_VALUE_MAX_BYTES,
                    unit = CardDraftProblem.LimitUnit.BYTES,
                ),
            )
        }

        if ((note?.characters() ?: 0) > CardLimits.NOTE_MAX_CHARACTERS) {
            put(CardField.NOTE, tooLongCharacters(CardLimits.NOTE_MAX_CHARACTERS))
        }
    }

/**
 * ⚠️ 码值用 `isEmpty` 而不是 `isBlank`：空格在 Code 39 / Code 128 里是**合法载荷字符**，
 * 而 `isBlank` 会把一个全空格的码值判成「没填」。它填没填由 `isEmpty` 说了算，
 * 那串空格编不编得出来由 `core:barcode` 说了算 —— 两件事别混。
 *
 * 标题相反，用 `isBlank`：一个全是空格的标题在列表里就是一片空白，
 * 而 [Card.initial] 对它返回空串。
 */
private fun String.characters(): Int = codePointCount(0, length)

private fun String.utf8Bytes(): Int = toByteArray(Charsets.UTF_8).size

private fun tooLongCharacters(limit: Int) =
    CardDraftProblem.TooLong(limit = limit, unit = CardDraftProblem.LimitUnit.CHARACTERS)
