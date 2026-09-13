package de.ncards.debug

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import dagger.hilt.android.AndroidEntryPoint
import de.ncards.core.common.user.CurrentUserIdStore
import de.ncards.core.database.dao.CardDao
import de.ncards.core.database.dao.CardMemberDao
import de.ncards.core.database.entity.CardEntity
import de.ncards.core.database.entity.CardMemberEntity
import de.ncards.core.model.card.CardColor
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import timber.log.Timber
import javax.inject.Inject
import kotlin.random.Random

/**
 * **只在 debug 构建里存在**的造数据入口。
 *
 * ============================================================================
 * 它为什么必须存在
 * ============================================================================
 * T-153 交付时，录入卡的三条路一条都还不存在（T-155 手输 / T-156 扫码 /
 * T-157 图片）。于是本卡的两条验收标准**没有任何办法执行**：
 *
 * - 「200 张卡的滚动无掉帧」（§9.1：P99 帧耗时 < 16.6 ms）
 * - `android/README.md`「本地库加密」那段手工 `adb` 验证 ——
 *   它逐字写着「第一个真实消费者是 **T-153 的钱包列表**，从那时起下面这段才有意义」，
 *   而它的第一步是「在应用里做一次会写库的操作」。
 *
 * ============================================================================
 * ⚠️ 为什么是 BroadcastReceiver 而不是一个按钮
 * ============================================================================
 * 按钮要加在 `feature:wallet` 的 `src/main` 里 —— 那就进了 release，
 * 然后得靠一个 `BuildConfig.DEBUG` 分支把它藏起来。而 R8 只在**它能证明**
 * 那个分支恒假时才剥掉，一旦有人把判断写复杂一点，造数据的代码就跟着上架了。
 *
 * 放在 `app/src/debug/` 里，它**在 release 构建中根本不被编译**，
 * 不需要任何人记得加判断。代价是触发方式是一行 `adb`，而那正好也是
 * README 里那段验证步骤的语境。
 *
 * ```bash
 * ./gradlew :app:installDebug
 * R=de.ncards.debug/de.ncards.debug.DebugSeedReceiver
 *
 * # SQLCipher 手工验证：不需要登录，随便给一个 uuid 就能把库建起来
 * adb shell am broadcast -a de.ncards.debug.SEED -n $R --es user 0192f3a1-b2c3-7d4e-8f01-00000000a11a --ei count 200
 *
 * # 想在钱包界面上看见它们：先登录，然后省掉 --es user（用会话里的那个人）
 * adb shell am broadcast -a de.ncards.debug.SEED -n $R --ei count 200
 * adb shell am broadcast -a de.ncards.debug.SEED -n $R --ei count 0   # 清空
 * ```
 *
 * ⚠️ 造出来的卡**不入 outbox**：它们是假的，推到服务端只会得到一堆 404/409。
 * 因此它们在列表里恒为 `SYNCED`（`observeWallet` 的派生逻辑：outbox 里没有记录
 * 就是 SYNCED）。要看 `PENDING` / `FAILED` 徽章，得用真的置顶/拖拽操作
 * —— 那些是会入队的。
 */
@AndroidEntryPoint
internal class DebugSeedReceiver : BroadcastReceiver() {
    @Inject
    lateinit var cards: CardDao

    @Inject
    lateinit var members: CardMemberDao

    @Inject
    lateinit var currentUser: CurrentUserIdStore

    override fun onReceive(
        context: Context,
        intent: Intent,
    ) {
        val count = intent.getIntExtra(EXTRA_COUNT, DEFAULT_COUNT)

        // 卡是按成员挂的（`card_members.user_id`），所以造数据必须有一个 user id。
        //
        // ⚠️ 允许从 `--es user <uuid>` 传一个进来，而不是硬性要求先登录。
        // 这不是图方便，是因为两条验证需要的东西不一样：
        //
        // - `android/README.md` 的 **SQLCipher 手工验证**只需要「有人真的打开过库」。
        //   本接收器自己注入了 `CardDao` —— 一次广播就会走完
        //   Keystore → passphrase → 开库 → 写入 的全程，**不需要会话**。
        //   T-009 的落地记录说「第一个真实消费者是 T-153，从那时起手工步骤才有意义」，
        //   指的就是这条路径终于有人走了。
        // - 想在**钱包界面上**看到这些卡，那才要真的登录（NavHost 未登录时根本
        //   不会组合钱包），而且 id 必须与会话里的那个一致。
        val userId = intent.getStringExtra(EXTRA_USER) ?: currentUser.userId.value

        if (userId == null) {
            Timber.w("没有 user id：先登录，或者用 --es user <uuid> 指定一个")
            return
        }

        // goAsync() 让进程在协程跑完之前不被回收。造 200 行要几十毫秒，
        // 而 onReceive 返回之后系统随时可以杀掉它。
        val pending = goAsync()

        CoroutineScope(Dispatchers.IO).launch {
            try {
                seed(userId, count)
                Timber.i("已写入 %d 张假卡（user=%s）", count, userId)
            } finally {
                pending.finish()
            }
        }
    }

    private suspend fun seed(
        userId: String,
        count: Int,
    ) {
        // 先清空，让这个命令是幂等的 —— 连着跑两次 `--ei count 200` 该得到
        // 200 张而不是 400 张。card_members 由外键 CASCADE 一起清掉。
        cards.deleteByIds(cards.walletSnapshot(userId, Int.MAX_VALUE).map { it.card.id })

        if (count <= 0) return

        val now = System.currentTimeMillis()
        val random = Random(seed = count.toLong())

        val entities =
            List(count) { index ->
                CardEntity(
                    // UUIDv7 的形状（时间前缀 + 随机尾），不是真的 v7 ——
                    // 假数据不需要真的单调递增，只需要稳定且互不相同。
                    id = "0192f3a1-b2c3-7d4e-8f01-%012d".format(index),
                    ownerId = userId,
                    title = TITLES[index % TITLES.size] + " " + index,
                    merchantLabel = TITLES[index % TITLES.size],
                    color = CardColor.entries[index % CardColor.entries.size].wireName,
                    barcodeFormat = FORMATS[index % FORMATS.size],
                    barcodeValue = "%013d".format(random.nextLong(0, 9_999_999_999_999L)),
                    note = null,
                    expiresOn = null,
                    revision = 1,
                    memberCount = 1,
                    createdAt = now - index,
                    updatedAt = now - index,
                )
            }

        cards.upsert(entities)
        members.upsert(
            entities.mapIndexed { index, card ->
                CardMemberEntity(
                    cardId = card.id,
                    userId = userId,
                    role = "owner",
                    sortOrder = index,
                    // 前三张置顶，好让置顶区与非置顶区都有内容可看
                    // —— 拖拽的区段隔离要对着两个非空区段才试得出来。
                    isPinned = index < PINNED_COUNT,
                    addedBy = null,
                    joinedAt = now,
                )
            },
        )
    }

    private companion object {
        const val EXTRA_COUNT = "count"

        /** 给谁造卡。缺省用当前会话里的那个人。见 onReceive 里的说明。 */
        const val EXTRA_USER = "user"

        /** §9.1 的滚动预算就是按 200 张卡写的。 */
        const val DEFAULT_COUNT = 200
        const val PINNED_COUNT = 3

        /**
         * ⚠️ 德语长词是**故意**的：验收标准点名
         * 「德语长词（`Benachrichtigungseinstellungen`）不截断」，
         * 而造数据要能把那个情况造出来，否则那条标准只能靠改代码去看。
         */
        val TITLES =
            listOf(
                "REWE",
                "dm",
                "Lidl Plus",
                "PAYBACK",
                "DeutschlandCard",
                "Müller",
                "Benachrichtigungseinstellungen",
                "Höffner",
                "Bahnhofstraße",
            )

        val FORMATS = listOf("EAN_13", "CODE_128", "QR_CODE", "PDF_417", "AZTEC")
    }
}
