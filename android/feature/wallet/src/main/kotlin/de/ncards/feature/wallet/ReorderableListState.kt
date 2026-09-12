package de.ncards.feature.wallet

import androidx.compose.foundation.gestures.detectDragGesturesAfterLongPress
import androidx.compose.foundation.lazy.LazyListState
import androidx.compose.runtime.Stable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.input.pointer.pointerInput
import kotlin.math.roundToInt

/**
 * 手写的长按拖拽重排。
 *
 * ============================================================================
 * 为什么不引一个库
 * ============================================================================
 * `gradle/libs.versions.toml` 是「唯一依赖声明处」，且它的规则第 3 条写着
 * 「只声明**已经在用**的东西」。成熟的 Compose 重排库（`sh.calvin.reorderable`
 * 之类）确实存在，但它们要解决的是一个比这里宽得多的问题
 * （多列、嵌套、自动滚动、跨列表拖拽）。本屏要的是一个单列、单区段的重排，
 * 而那是下面这一百来行。
 *
 * ============================================================================
 * ⚠️ 拖拽状态**不进 ViewModel**
 * ============================================================================
 * 这是本文件最重要的一条。手指每移动一像素都会更新 [dragOffset]，
 * 而 §9.1 给列表滚动的预算是 **P99 帧耗时 < 16.6 ms（200 张卡）**。
 *
 * 把这些放进 `StateFlow<UiState>` 的后果：每一帧位移都是一次
 * `_state.update { it.copy(...) }` → 一个新的 `UiState` → 整个列表重组。
 * 200 张卡的列表在拖拽时会肉眼可见地掉帧。
 *
 * 所以位移住在 Compose 的局部状态里，只有**松手那一刻**才调一次
 * `onReorder` 把结果写进 Room。中间态从来不离开这一屏。
 *
 * ============================================================================
 * ⚠️ 落点用的是**可见项的实际位置**，不是「位移 ÷ 行高」
 * ============================================================================
 * 行高不是常量：标题可以是两行，德语长词会换行，字体缩放到 200% 时整行会长高
 * （§11.2 要求它能长高）。用一个固定行高去算落点，在这些情况下全是错的。
 * [targetIndexFor] 因此去问 `LazyListState` 每一项**真实**的 offset 与 size。
 */
@Stable
internal class ReorderableListState(
    private val listState: LazyListState,
    private val onReorder: (fromIndex: Int, toIndex: Int) -> Unit,
) {
    /** 正在被拖的那一项在列表里的下标，`null` = 没有人在拖。 */
    var draggingIndex by mutableStateOf<Int?>(null)
        private set

    /** 从按下那一刻算起的累计竖直位移（像素）。 */
    var dragOffset by mutableFloatStateOf(0f)
        private set

    /** 拖拽过程中这一项该被画到哪里（像素偏移）。没在拖就是 0。 */
    fun offsetFor(index: Int): Float = if (index == draggingIndex) dragOffset else 0f

    fun onDragStart(index: Int) {
        draggingIndex = index
        dragOffset = 0f
    }

    fun onDrag(delta: Float) {
        dragOffset += delta
    }

    fun onDragEnd() {
        val from = draggingIndex ?: return
        val to = targetIndexFor(from, dragOffset)

        draggingIndex = null
        dragOffset = 0f

        // 没挪动就什么都不做 —— Repository 那边也会再挡一次
        // （`moved` 返回 null），两层都挡是因为这一层能省掉一次跨线程调用。
        if (to != from) onReorder(from, to)
    }

    fun onDragCancel() {
        draggingIndex = null
        dragOffset = 0f
    }

    /**
     * 拖到哪一项上了。
     *
     * 拿被拖项的**中心点**去和其他项的区间比：中心越过了下一项的中点，
     * 就算换过去了。这比「位移 ÷ 行高」稳，因为它不假设行等高。
     */
    private fun targetIndexFor(
        from: Int,
        offset: Float,
    ): Int {
        val items = listState.layoutInfo.visibleItemsInfo
        val dragged = items.firstOrNull { it.index == from }
        val draggedCenter = dragged?.let { it.offset + it.size / 2f + offset }

        val landing =
            draggedCenter?.let { center ->
                items
                    .filter { it.index != from }
                    .firstOrNull { center.toInt() in it.offset..(it.offset + it.size) }
            }

        // 被拖的那项已经滚出可见范围，或者没落在任何一项上 —— 都算没挪动。
        return landing?.index ?: from
    }

    /**
     * 挂在每一项上的手势。
     *
     * `detectDragGesturesAfterLongPress` 而不是 `detectDragGestures`：
     * 列表本身要能竖直滚动，而一个立即响应的竖直拖拽会把滚动整个吃掉 ——
     * 用户就再也滑不动这个列表了。长按是这两者唯一能共存的方式，
     * 也是 Android 上「我要拖动它」的既有手势约定。
     *
     * ⚠️ TalkBack 打开时长按会被无障碍服务拦截，所以这条路径对视障用户是**不通的**。
     * 等价物是 `WalletCardRow` 上的 `customActions`（「向上移动」/「向下移动」），
     * 那不是可选的锦上添花，是这个功能对他们唯一的入口。
     */
    fun Modifier.reorderableItem(index: Int): Modifier =
        pointerInput(index) {
            detectDragGesturesAfterLongPress(
                onDragStart = { onDragStart(index) },
                onDrag = { change, amount ->
                    change.consume()
                    onDrag(amount.y)
                },
                onDragEnd = { onDragEnd() },
                onDragCancel = { onDragCancel() },
            )
        }
}

/**
 * 记住一个 [ReorderableListState]。
 *
 * [onReorder] 走 `rememberUpdatedState` 的等价物（每次重组都用最新的那个 lambda），
 * 是因为它捕获了 ViewModel —— 而 `remember(listState)` 只在 listState 变化时重建。
 */
@androidx.compose.runtime.Composable
internal fun rememberReorderableListState(
    listState: LazyListState,
    onReorder: (fromIndex: Int, toIndex: Int) -> Unit,
): ReorderableListState {
    val currentOnReorder by androidx.compose.runtime.rememberUpdatedState(onReorder)

    return remember(listState) {
        ReorderableListState(listState) { from, to -> currentOnReorder(from, to) }
    }
}

/** `Float` 的像素位移 → `IntOffset` 要的整数。 */
internal fun Float.toPixelOffset(): Int = roundToInt()
