package de.ncards.feature.cardedit

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import de.ncards.core.barcode.format.validateBarcodePayload
import de.ncards.core.model.barcode.BarcodeFormat
import de.ncards.core.model.card.Card
import de.ncards.core.model.card.CardColor
import de.ncards.core.model.card.CardDraft
import de.ncards.core.model.card.problems
import de.ncards.core.model.navigation.CardEditRoute
import de.ncards.data.card.CardRepository
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.channels.Channel
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.filterNotNull
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.flatMapLatest
import kotlinx.coroutines.flow.flow
import kotlinx.coroutines.flow.flowOf
import kotlinx.coroutines.flow.receiveAsFlow
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import java.time.LocalDate
import javax.inject.Inject
import kotlin.time.Duration.Companion.seconds

/**
 * 新增/编辑表单的 ViewModel（T-155）。
 *
 * ============================================================================
 * ⚠️ 本类最容易写错的一条：**表单状态不能被 Room 冲掉**
 * ============================================================================
 * 直觉写法是让 `repository.observeCard(id)` 直接驱动表单 —— 那样每来一帧都会
 * 把用户正在打的字覆盖掉。而这个流**会**发：它建在 `observeWallet` 之上
 * （`DefaultCardRepository.observeCard` 的注释写着这件事），钱包里任何一张
 * 别的卡变一下、任何一次下行同步、任何一次拖拽排序，它都可能再发一次。
 *
 * 落法是：**只用第一帧非空发射播种表单**（[seeded] 守着），
 * 此后表单是 [draft] 这个 `MutableStateFlow` 自己的。继续订阅那个流只为
 * 侦测「卡没了」→ [CardEditUiState.Missing]。
 *
 * 代价是「别的设备同时改了这张卡」不会实时反映到正开着的表单上 ——
 * 那是对的：用户正在编辑，我们不该在他手底下换掉内容。冲突由 §5.4.3 的
 * 三方合并在推送时解决（T-251），不是在表单里。
 *
 * ============================================================================
 * 新建与编辑是同一个 ViewModel，判据是「取不取得到 cardId」
 * ============================================================================
 * 两个路由（`CardCreateRoute` / `CardEditRoute`）指向同一个目的地实现。
 * `CardCreateRoute` 是 `data object`，没有参数，所以 `SavedStateHandle` 里
 * 根本没有 [CARD_ID_KEY] 那个键 —— 取不到就是新建。
 *
 * ⚠️ 与 `CardDetailViewModel` 不同，这里**不能** `checkNotNull` ——
 * 在那边缺 key 是装配错误，在这里它是一种合法模式。
 */
@HiltViewModel
class CardEditViewModel
    @Inject
    constructor(
        savedStateHandle: SavedStateHandle,
        private val repository: CardRepository,
    ) : ViewModel() {
        private val cardId: String? = savedStateHandle[CARD_ID_KEY]

        private val mode = if (cardId == null) CardEditMode.CREATE else CardEditMode.EDIT

        /**
         * 用户当前填的东西。**这是本屏唯一的真相源**（播种之后）。
         *
         * 新建模式的初值不是空壳：颜色要是 [CardColor.DEFAULT]、码制要有一个默认值，
         * 否则色板与下拉框第一帧是空的。
         *
         * ⚠️ 颜色的初值取 `CardColor.DEFAULT.wireName` 而不是随便挑一个 ——
         * `CardColor` 的 KDoc 点名要求本卡的色板按 `entries` 排，
         * 于是「什么都不选」与「选第一格」拿到同一个色，
         * 不会出现「我明明没选，怎么是绿的」。
         */
        private val draft =
            MutableStateFlow(
                CardEditForm(
                    colorWire = CardColor.DEFAULT.wireName,
                    barcodeFormat = DEFAULT_FORMAT,
                    // ⚠️ 初值也要过一遍校验。不过的话空表单的 `problems` 是空 Map，
                    // 于是 `canSave` 为 true —— 用户一打开「新建」直接点保存，
                    // 就建出一张标题为空、码值为空的卡。红字不显示（showProblems 还是
                    // false）所以界面上看不出任何异常。
                ).revalidated(),
            )

        /**
         * 编辑模式下，那张卡的内容有没有已经灌进 [draft]。
         *
         * 只用来读，不参与状态流 —— 播种由 `init` 里那条协程负责。
         */
        private var seeded = cardId == null

        private val savingState = MutableStateFlow(false)

        /**
         * 「保存成功了，回去吧」。
         *
         * ⚠️ 这是**一次性事件**，不是状态 —— §10.4 认可的形状是
         * `Channel` + `receiveAsFlow()`，由 `core:ui` 的 `ObserveEvents` 消费。
         * 本卡是那个工具的第一个消费者。
         *
         * 放进 UiState 的话，旋转屏幕（或任何一次重组后的重新收集）会让它再触发
         * 一次返回 —— 用户会发现自己莫名其妙退了两层。
         *
         * `Channel.BUFFERED` 而不是 `CONFLATED`：保存只会发生一次，
         * 但真丢了的话用户会卡在一个已经保存完的表单上。
         */
        private val saved = Channel<Unit>(Channel.BUFFERED)
        val savedEvents: Flow<Unit> = saved.receiveAsFlow()

        /**
         * 「我是谁」解析到了没有 —— 与 `CardDetailViewModel` / `WalletViewModel`
         * 是同一套语义、同一个宽限期。没有它，[CardEditUiState.Missing] 会在
         * 每一次冷启动的头几十毫秒误报一次。
         */
        @OptIn(ExperimentalCoroutinesApi::class)
        private val userPresence: Flow<UserPresence> =
            repository.observeCurrentUserId().flatMapLatest { userId ->
                if (userId != null) {
                    flowOf(UserPresence.Known)
                } else {
                    flow {
                        emit(UserPresence.Resolving)
                        delay(USER_RESOLUTION_GRACE)
                        emit(UserPresence.Missing)
                    }
                }
            }

        init {
            // ⚠️ 播种在这里，而**不是**在下面那个 `combine` 的 transform 里。
            //
            // 写进 transform 是很自然的第一版（那里正好拿得到卡），但它有两个问题：
            // ① transform 应当是纯的 —— 在里面改 `draft` 会让 `state` 的每次重算
            //    都带一次副作用；② `stateIn(WhileSubscribed)` 下 transform **只在
            //    有人收集 `state` 时才跑**，于是「没人看着这一屏但调用了 onSave」
            //    会把一张空表单写回那张卡，把它的内容清掉。
            //
            // `viewModelScope` 里自己收一次就没有这两个问题：播种与谁在看无关。
            if (cardId != null) {
                viewModelScope.launch {
                    // 只要第一帧非空的那张卡。`first {}` 拿到就结束收集 ——
                    // 此后表单归用户，上游再发什么都不再覆盖它（见类注释）。
                    val card = repository.observeCard(cardId).filterNotNull().first()
                    draft.value = card.toForm()
                    seeded = true
                }
            }
        }

        val state: StateFlow<CardEditUiState> =
            combine(
                cardId?.let(repository::observeCard) ?: flowOf(null),
                draft,
                savingState,
                userPresence,
            ) { card, form, isSaving, presence ->
                stateOf(card, form, isSaving, presence)
            }.stateIn(
                scope = viewModelScope,
                started = SharingStarted.WhileSubscribed(STOP_TIMEOUT_MILLIS),
                initialValue = if (mode == CardEditMode.CREATE) editing(draft.value) else CardEditUiState.Loading,
            )

        fun onTitleChange(value: String) = edit { copy(title = value) }

        fun onMerchantLabelChange(value: String) = edit { copy(merchantLabel = value) }

        fun onColorChange(color: CardColor) = edit { copy(colorWire = color.wireName) }

        fun onBarcodeFormatChange(format: BarcodeFormat) = edit { copy(barcodeFormat = format) }

        fun onBarcodeValueChange(value: String) = edit { copy(barcodeValue = value) }

        fun onNoteChange(value: String) = edit { copy(note = value) }

        fun onExpiresOnChange(value: LocalDate?) = edit { copy(expiresOn = value) }

        /**
         * 保存。
         *
         * ⚠️ 表单不合法时**不保存，改成显示红字**（`showProblems = true`）——
         * 而不是把保存按钮做成禁用的。禁用按钮在这里是敌意的：用户按了没反应，
         * 而红字不出现，他不知道差哪儿。这与钱包空状态那个「画出来但按不动」
         * 不是一回事 —— 那边按钮背后**根本没有功能**，这边有。
         */
        fun onSave() {
            val form = draft.value

            if (!form.canSave) {
                draft.update { it.copy(showProblems = true) }
                return
            }

            // 重复点击：保存中不再受理。§4.3 铁律二下保存是本地写，很快，
            // 但「很快」不是「原子」—— 连点两下会建出两张卡。
            if (savingState.value) return

            savingState.value = true

            viewModelScope.launch {
                if (cardId == null) {
                    repository.createCard(form.toDraft())
                } else {
                    repository.updateCard(cardId, form.toDraft())
                }

                savingState.value = false
                saved.send(Unit)
            }
        }

        /**
         * 每次编辑都重算两层校验，但 [CardEditForm.showProblems] 决定显不显示。
         *
         * 重算而不是「保存时才算」：[CardEditForm.canSave] 要实时反映，
         * 而且用户改对之后红字应当**立刻**消失 —— 让他等到下一次点保存才知道
         * 改对了没有，是最让人烦躁的一种表单。
         */
        private fun edit(block: CardEditForm.() -> CardEditForm) {
            draft.update { current -> current.block().revalidated() }
        }

        private fun stateOf(
            card: Card?,
            form: CardEditForm,
            isSaving: Boolean,
            presence: UserPresence,
        ): CardEditUiState =
            when {
                // 新建模式永远直接进表单：它不依赖任何一张卡。
                mode == CardEditMode.CREATE -> editing(form, isSaving)

                // ⚠️ 卡先于用户判定：已经拿到卡了就没什么好等的。
                // 但表单还没播种时仍然是 Loading —— 否则会闪一帧空表单，
                // 而用户点的是「编辑」。
                card != null -> if (seeded) editing(form, isSaving) else CardEditUiState.Loading

                presence == UserPresence.Resolving -> CardEditUiState.Loading

                presence == UserPresence.Missing -> CardEditUiState.Error(CardEditError.CurrentUserUnknown)

                // ⚠️ 已经播过种了，卡却没了 —— owner 删了它、或我被移出成员。
                // 这是**正常结局**，不是错误。见 CardEditUiState 的类注释。
                else -> CardEditUiState.Missing
            }

        private fun editing(
            form: CardEditForm,
            isSaving: Boolean = false,
        ) = CardEditUiState.Editing(form = form, mode = mode, isSaving = isSaving)

        /** 见 [userPresence]。与另外两个 feature 的同名枚举是同一套语义。 */
        private enum class UserPresence {
            Resolving,
            Known,
            Missing,
        }

        companion object {
            /**
             * 路由参数的 key。
             *
             * 用属性引用而不是字面量 `"cardId"`：改了属性名编译期就会跟着改，
             * 而字面量会静默漂掉 —— 症状是点「编辑」却建了一张新卡
             * （取不到 id ⇒ 本类判定成新建模式）。`CardEditIdKeyTest` 钉住它。
             */
            val CARD_ID_KEY: String = CardEditRoute::cardId.name

            /**
             * 新建时的默认码制。
             *
             * EAN-13 是德国零售最常见的那个（Payback / DeutschlandCard / 超市会员卡），
             * 所以它省下最多的一次下拉操作。它也**不是** `BarcodeFormat.entries.first()`
             * —— 那只是巧合地相同，写死是为了让「换默认码制」不必去动枚举顺序。
             */
            private val DEFAULT_FORMAT = BarcodeFormat.EAN_13

            /** 与另外两个 feature 的同名常量同一个理由、同一个数。 */
            private val USER_RESOLUTION_GRACE = 3.seconds

            private const val STOP_TIMEOUT_MILLIS = 5_000L
        }
    }

/**
 * 一张卡 → 表单初值。
 *
 * ⚠️ `colorWire` 原样带走，**不经过 `CardColor`** —— 一张颜色是 `mint_600`
 * 的卡（新版本 App 建的）在本版本里色板一格都不高亮，而用户不动颜色就保存时，
 * 写回去的仍然是 `mint_600`。经过枚举就等于在打开表单的那一刻把它改成了蓝色，
 * 而服务端没有任何机制会发现（见 `Card.color` 的注释）。
 *
 * 可空字段落到空串：`TextField` 吃的是 `String`，而 `null` 与 `""` 在表单里
 * 是同一件事（「没填」）。写库前再归一回 `null`，那一步在 `data:card`。
 */
internal fun Card.toForm(): CardEditForm =
    CardEditForm(
        title = title,
        merchantLabel = merchantLabel.orEmpty(),
        colorWire = colorWire,
        barcodeFormat = barcodeFormat,
        barcodeValue = barcodeValue,
        note = note.orEmpty(),
        expiresOn = expiresOn,
    ).revalidated()

/** 表单 → 写入模型。 */
internal fun CardEditForm.toDraft(): CardDraft =
    CardDraft(
        title = title.trim(),
        merchantLabel = merchantLabel.trim(),
        colorWire = colorWire,
        barcodeFormat = barcodeFormat,
        // ⚠️ 码值**不 trim**：空格在 Code 39 / Code 128 里是合法载荷字符，
        // 而扫码枪读出来的就是那个字符串。修剪它会静默改掉用户的卡。
        barcodeValue = barcodeValue,
        note = note.trim(),
        expiresOn = expiresOn,
    )

/**
 * 重算两层校验。
 *
 * **两层分别住在两个模块，这不是巧合**：长度上限来自契约（`core:model` 的
 * `CardDraft.problems()`），码值内容规则来自编码器（`core:barcode` 的
 * `validateBarcodePayload`，T-152 点名要求落点在那边）。
 * 在这里合流是 feature 层该做的事，在这里**实现**其中任何一条则不是。
 */
internal fun CardEditForm.revalidated(): CardEditForm {
    val draft = toDraft()

    return copy(
        problems = draft.problems(),
        payloadProblem = validateBarcodePayload(barcodeFormat, barcodeValue),
    )
}
