package de.ncards.feature.cardedit

import de.ncards.core.barcode.format.BarcodePayloadProblem
import de.ncards.core.model.barcode.BarcodeFormat
import de.ncards.core.model.card.CardDraftProblem
import de.ncards.core.model.card.CardField
import java.time.LocalDate

/**
 * 新增/编辑表单的状态（§10.4：每个 feature 一个 sealed interface，
 * **禁止**用多个独立布尔标志表达状态）。
 *
 * ============================================================================
 * ⚠️ [Missing] 与 [Error] 不是同一件事 —— 与 `CardDetailUiState` 同一条约定
 * ============================================================================
 * - [Missing]：这张卡**不在你的钱包里了**。owner 删了它、你被移出成员，
 *   或者解除好友把共享卡一并收走 —— 全都是**正常结局**，而且完全可能发生在
 *   表单正开着的时候。出路是「返回」。
 * - [Error]：本机出了故障（今天只有一种：令牌在但读不出「我是谁」）。
 *
 * 并成一格的后果：一个卡刚被删掉的用户看到的是「出错了」—— 而什么都没错。
 * §16 R12 点名这类「卡凭空消失」的场景**必须**让用户分得清是不是 Bug。
 *
 * ============================================================================
 * 新建模式没有 [Loading] / [Missing] —— 但它们仍然在这个类型里
 * ============================================================================
 * 新建时第一帧就是 [Editing]（空表单），也永远不会 [Missing]。
 * 不为此拆成两个 UiState：两套状态机意味着两套 `when`、两个 Screen、两份预览，
 * 而它们 90% 的分支是一样的。[CardEditMode] 带的那点差别只影响标题栏文案。
 */
sealed interface CardEditUiState {
    /**
     * 编辑模式下还没从 Room 读到那张卡。
     *
     * ⚠️ 它也是 [Missing] 的**宽限期**：`observeCard` 在「还不知道我是谁」时
     * 发的也是 `null`，而那时说「这张卡已不在你的钱包里」是假话。
     * 见 [CardEditViewModel] 里那个 `USER_RESOLUTION_GRACE`。
     */
    data object Loading : CardEditUiState

    /**
     * 表单开着。
     *
     * @property form 用户当前填的东西。**它是 ViewModel 自己的状态，不是 Room 的投影** ——
     *   见 [CardEditForm] 的注释。
     * @property mode 新建还是编辑。只影响标题栏与按钮文案。
     * @property isSaving 保存进行中（按钮转圈、输入框禁用）。
     *   ⚠️ 它是 [Editing] **内部**的一个字段而不是一格独立状态：保存中时表单
     *   仍然完整地在屏幕上，用户看到的是同一个界面。给它单开一格会逼 Screen
     *   在两个分支里各画一遍同样的表单。
     */
    data class Editing(
        val form: CardEditForm,
        val mode: CardEditMode,
        val isSaving: Boolean = false,
    ) : CardEditUiState

    /** 这张卡已经不在这台设备的钱包里了。**不是**错误，见类注释。 */
    data object Missing : CardEditUiState

    /** 出事了。 */
    data class Error(
        val reason: CardEditError,
    ) : CardEditUiState
}

/** 新建还是编辑。 */
enum class CardEditMode { CREATE, EDIT }

/**
 * 表单里的字段 + 每个字段当前的问题。
 *
 * ============================================================================
 * ⚠️ [colorWire] 是 `String` 而不是 `CardColor`
 * ============================================================================
 * 与 `CardDraft.colorWire` 同一条理由，而且**表单正是那条理由发作的地方**：
 * 一张卡的颜色是本版本不认识的 `mint_600` 时，色板上一格都不该高亮，
 * 而用户没动颜色就保存的话，写回去的必须仍然是 `mint_600`。
 * 存成枚举就等于在打开表单的那一刻把它改成了蓝色。
 *
 * ============================================================================
 * ⚠️ [problems] / [payloadProblem] 只在**试过保存之后**才该显示
 * ============================================================================
 * 它们从第一帧起就算得出来（空表单当然是「标题没填」），但一打开就红着
 * 一片对用户是敌意的。由 [showProblems] gate 住，见 [CardEditViewModel]。
 */
data class CardEditForm(
    val title: String = "",
    val merchantLabel: String = "",
    val colorWire: String,
    val barcodeFormat: BarcodeFormat,
    val barcodeValue: String = "",
    val note: String = "",
    val expiresOn: LocalDate? = null,
    val problems: Map<CardField, CardDraftProblem> = emptyMap(),
    val payloadProblem: BarcodePayloadProblem? = null,
    val showProblems: Boolean = false,
) {
    /** 保存按钮能不能按。 */
    val canSave: Boolean get() = problems.isEmpty() && payloadProblem == null

    /** 该在哪个输入框下面显示红字。`showProblems` 之前一律不显示。 */
    fun problemOf(field: CardField): CardDraftProblem? = if (showProblems) problems[field] else null

    /** 码值那一栏的问题：长度问题优先于编码问题（先说最基础的那条）。 */
    val visibleBarcodeProblem: BarcodePayloadProblem?
        get() = if (showProblems && problems[CardField.BARCODE_VALUE] == null) payloadProblem else null
}

/**
 * 表单能出的错。
 *
 * ⚠️ 是 sealed 而不是字符串：§10.4 要求 ViewModel **不得** import Android
 * framework 类，所以它不能在那里 `getString`。文案由 UI 层的
 * `CardEditMessages.kt` 映射 —— 与 `feature:wallet` / `feature:carddetail` 同一个做法。
 */
sealed interface CardEditError {
    /**
     * 令牌在，但本机读不出「我是谁」。
     *
     * 与 `WalletError.CurrentUserUnknown` / `CardDetailError.CurrentUserUnknown`
     * 是同一件事、同一套文案口径：要说的**不是**「这张卡没了」，
     * 而是「本机数据暂时读不出来，联网后会恢复」。
     */
    data object CurrentUserUnknown : CardEditError
}
