package de.ncards.feature.cardedit

import androidx.annotation.StringRes
import androidx.compose.runtime.Composable
import androidx.compose.ui.res.stringResource
import de.ncards.core.barcode.format.BarcodePayloadProblem
import de.ncards.core.model.card.CardColor
import de.ncards.core.model.card.CardDraftProblem

/*
 * 把 ViewModel 那边的**值**映射成文案。
 *
 * §10.4：ViewModel 不得 import Android framework 类，所以它给的是 sealed 的
 * 原因对象，`getString` 发生在这里。与 `WalletMessages.kt` / `CardDetailMessages.kt`
 * 是同一个做法。
 */

/** 长度层面的问题（`core:model` 的判据）。 */
@Composable
internal fun CardDraftProblem.message(): String =
    when (this) {
        CardDraftProblem.Blank -> {
            stringResource(R.string.cardedit_problem_blank)
        }

        is CardDraftProblem.TooLong -> {
            when (unit) {
                // ⚠️ 字符与字节分成两条文案，不是一条里塞个单位词。
                // 码值按字节算（§7.5），而对德语用户来说「1024 Zeichen」与
                // 「1024 Byte」不是一回事 —— 一个变音字母占两字节，
                // 他数着字符会觉得我们在骗他。
                CardDraftProblem.LimitUnit.CHARACTERS -> {
                    stringResource(R.string.cardedit_problem_too_long_characters, limit)
                }

                CardDraftProblem.LimitUnit.BYTES -> {
                    stringResource(R.string.cardedit_problem_too_long_bytes, limit)
                }
            }
        }
    }

/**
 * 码值编不出来的原因（`core:barcode` 的判据）。
 *
 * 每一条都要让用户知道**怎么改**。T-152 只做到「无效」，而本卡的整个
 * 校验器就是为了这几行文案存在的。
 */
@Composable
internal fun BarcodePayloadProblem.message(): String =
    when (this) {
        BarcodePayloadProblem.Empty -> {
            stringResource(R.string.cardedit_payload_empty)
        }

        BarcodePayloadProblem.UnsupportedFormat -> {
            stringResource(R.string.cardedit_payload_unsupported_format)
        }

        is BarcodePayloadProblem.IllegalCharacters -> {
            stringResource(R.string.cardedit_payload_illegal_characters, sample)
        }

        is BarcodePayloadProblem.WrongLength -> {
            stringResource(R.string.cardedit_payload_wrong_length, expected.joinedForHumans())
        }

        is BarcodePayloadProblem.TooLong -> {
            stringResource(R.string.cardedit_payload_too_long, maxLength)
        }

        BarcodePayloadProblem.OddLength -> {
            stringResource(R.string.cardedit_payload_odd_length)
        }

        BarcodePayloadProblem.ChecksumMismatch -> {
            stringResource(R.string.cardedit_payload_checksum)
        }

        BarcodePayloadProblem.NotEncodable -> {
            stringResource(R.string.cardedit_payload_not_encodable)
        }
    }

/**
 * `[12, 13]` → 「12 oder 13」。
 *
 * ⚠️ 连接词走 `strings.xml`（`cardedit_length_or`）而不是写死一个 `" oder "`，
 * 因为英语是 “or”—— 而 `checkComposeHardcodedText` 看不见拼接出来的字符串，
 * 它只扫字面量参数。这是本模块唯一一处会被那个门禁漏掉的地方。
 *
 * 今天所有码制的 `expected` 都恰好是两个值（ZXing 的 EAN/UPC writer 收
 * 「带校验位」与「不带校验位」两种）。多于两个时退化成逗号拼接 ——
 * 那一天要给它一条自己的文案，不是让这里静默变难看。
 */
@Composable
private fun List<Int>.joinedForHumans(): String =
    if (size == 2) {
        stringResource(R.string.cardedit_length_or, this[0], this[1])
    } else {
        joinToString(", ")
    }

/** 表单出错时整屏的文案（与 `carddetail` 同一套口径）。 */
@Composable
internal fun CardEditError.title(): String =
    when (this) {
        CardEditError.CurrentUserUnknown -> stringResource(R.string.cardedit_error_user_unknown_title)
    }

@Composable
internal fun CardEditError.body(): String =
    when (this) {
        CardEditError.CurrentUserUnknown -> stringResource(R.string.cardedit_error_user_unknown_body)
    }

/**
 * 调色板的名字（§11.2：**不以颜色作为唯一信息载体**）。
 *
 * ⚠️ 这是一个穷举的 `when`，没有 `else` —— 加第 12 个色键时这里会编译失败，
 * 而那正是要的：一个没有名字的色块在 TalkBack 下只会被读成「按钮」，
 * 视障用户完全不知道自己选的是什么颜色，而这个缺陷在视觉上看不出来。
 *
 * 色**值**在 `core:designsystem` 的 `cardColorSchemeOf`，色**名**在这里 ——
 * 分开的理由与「键在 core:model、值在 designsystem」一致：`core:model` 没有
 * `res/`（§11.1 要求用户可见字符串都在 strings.xml 里）。
 */
@get:StringRes
internal val CardColor.nameRes: Int
    get() =
        when (this) {
            CardColor.BLUE -> R.string.cardedit_color_blue
            CardColor.INDIGO -> R.string.cardedit_color_indigo
            CardColor.TEAL -> R.string.cardedit_color_teal
            CardColor.GREEN -> R.string.cardedit_color_green
            CardColor.OLIVE -> R.string.cardedit_color_olive
            CardColor.ORANGE -> R.string.cardedit_color_orange
            CardColor.RED -> R.string.cardedit_color_red
            CardColor.PINK -> R.string.cardedit_color_pink
            CardColor.PURPLE -> R.string.cardedit_color_purple
            CardColor.BROWN -> R.string.cardedit_color_brown
            CardColor.SLATE -> R.string.cardedit_color_slate
        }
