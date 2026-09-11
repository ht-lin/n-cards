package de.ncards.data.auth

import de.ncards.core.crypto.KeyMaterialUnrecoverableException
import de.ncards.core.crypto.SecretStore
import de.ncards.core.network.api.model.Session
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import timber.log.Timber
import javax.inject.Inject
import javax.inject.Singleton

/**
 * 两枚令牌的唯一存放处（§7.1 的令牌表、§7.3 的客户端安全清单）。
 *
 * ```
 * Access  JWT (EdDSA)     15 分钟    内存 + SecretStore
 * Refresh 32B Base64url   90 天滑动  SecretStore，仅此一处
 * ```
 *
 * ============================================================================
 * ⚠️⚠️ 清会话用 [SecretStore.remove]，**绝不**用 [SecretStore.clear]
 * ============================================================================
 * `KeystoreSecretStore.clear()` 的语义是「清空全部条目**并丢弃包裹密钥**」，
 * 而 SQLCipher 的 `db_passphrase`（T-009，键名 `db_passphrase`）就存在
 * **同一个** `ncards_secrets_v1` 文件、由**同一把** Keystore 密钥包裹。
 *
 * 也就是说：在登出路径上调一次 `clear()`，用户整个本地数据库 ——
 * 连同 `sync_outbox` 里还没推上去的写入 —— 会变成永远解不开的密文。
 * 而这件事**在功能测试里完全看不出来**：登出照常跳登录页，重新登录照常进钱包
 * （那时库已经被当成「首次安装」重建了），只有用户自己发现卡没了。
 *
 * `SessionStoreTest` 因此专门断言 `FakeSecretStore.clearCount == 0`。
 *
 * ============================================================================
 * 为什么 access token 还要在内存里留一份
 * ============================================================================
 * §7.1 的表逐字写了「内存 + EncryptedSharedPreferences」，而它不是一句冗余：
 * [BearerAuthInterceptor] **每个请求**都要读一次 access token，
 * 而 `SecretStore` 的 KDoc 明写「这里每次读写都要过一次 Keystore，
 * 大对象或高频访问都会难看」。落盘那一半是为了冷启动不必先刷新一次。
 *
 * 内存那一份用 `@Volatile` + 写入口 `synchronized`：读发生在 OkHttp 的任意
 * dispatcher 线程上，写发生在刷新与登录路径上。
 */
@Singleton
internal class SessionStore
    @Inject
    constructor(
        private val secrets: SecretStore,
    ) {
        private val lock = Any()

        @Volatile
        private var cached: Tokens? = null

        @Volatile
        private var loaded = false

        private val _state = MutableStateFlow<SessionState>(SessionState.Unknown)

        /** 见 [SessionState]。首次被读到之前恒为 [SessionState.Unknown]。 */
        val state: StateFlow<SessionState> = _state.asStateFlow()

        fun accessToken(): String? = tokens()?.access

        fun refreshToken(): String? = tokens()?.refresh

        /**
         * 登录成功或刷新成功之后写入。
         *
         * ⚠️ 参数是生成的 [Session]，但 [Session] 这个类型**不出本模块** ——
         * 见 `AuthRepository` 的 KDoc。这个方法是它唯一的终点。
         */
        fun save(session: Session) {
            synchronized(lock) {
                secrets.put(KEY_ACCESS_TOKEN, session.accessToken.toByteArray(Charsets.UTF_8))
                secrets.put(KEY_REFRESH_TOKEN, session.refreshToken.toByteArray(Charsets.UTF_8))
                cached = Tokens(session.accessToken, session.refreshToken)
                loaded = true
                _state.value = SessionState.SignedIn
            }
        }

        /** 清空本机会话。见类注释：两次 `remove`，不是一次 `clear`。 */
        fun clear(reason: SignedOutReason) {
            synchronized(lock) {
                secrets.remove(KEY_ACCESS_TOKEN)
                secrets.remove(KEY_REFRESH_TOKEN)
                cached = null
                loaded = true
                _state.value = SessionState.SignedOut(reason)
            }
        }

        /**
         * 懒加载 + 缓存。首次调用会读两次 `SecretStore`（两趟 Keystore），之后走内存。
         */
        private fun tokens(): Tokens? {
            if (loaded) return cached

            return synchronized(lock) {
                if (loaded) return@synchronized cached

                val restored = readFromStore()
                cached = restored.tokens
                loaded = true
                _state.value =
                    if (restored.tokens == null) {
                        SessionState.SignedOut(restored.reason)
                    } else {
                        SessionState.SignedIn
                    }
                restored.tokens
            }
        }

        /**
         * ⚠️ [KeyMaterialUnrecoverableException] 是 [SecretStore.get] 的契约异常，
         * 调用方**必须**处理（那份 KDoc 的原话：「正确反应是清理重来而不是重试」）。
         *
         * 这里的「清理重来」是：把两个键删掉、当成未登录。范本是
         * `KeystoreDbPassphraseProvider.passphrase()` 的第三条分支。
         *
         * 不在这里调 `clear()` 的理由同类注释 —— 而且真要重建包裹密钥的话，
         * 那是 `DbPassphraseProvider` 的职责，它有自己的恢复路径。
         */
        private fun readFromStore(): Restored =
            try {
                val access = secrets.get(KEY_ACCESS_TOKEN)?.toString(Charsets.UTF_8)
                val refresh = secrets.get(KEY_REFRESH_TOKEN)?.toString(Charsets.UTF_8)
                // refresh 是会话的根：没有它，access 过期之后就什么都做不了了。
                Restored(
                    tokens = refresh?.let { Tokens(access, it) },
                    reason = SignedOutReason.NeverSignedIn,
                )
            } catch (e: KeyMaterialUnrecoverableException) {
                // 不打印密文、不打印 key 之外的任何内容（§7.3）。
                // release 构建里没有种 Tree，这行是空操作（见 NcardsApplication）。
                Timber.w(e, "本机会话已不可解，按未登录处理")
                secrets.remove(KEY_ACCESS_TOKEN)
                secrets.remove(KEY_REFRESH_TOKEN)
                Restored(tokens = null, reason = SignedOutReason.KeyMaterialLost)
            }

        /** [reason] 只在 [tokens] 为 null 时有意义 —— 它是「为什么没读出会话」。 */
        private data class Restored(
            val tokens: Tokens?,
            val reason: SignedOutReason,
        )

        /**
         * `access` 可以为 null 而 `refresh` 不行：`SecretStore` 的两次 `put` 不是
         * 一个原子操作，进程在两者之间被杀会留下半截状态。以 refresh 为准是对的 ——
         * 缺 access 只意味着下一个请求会 401 然后刷新，缺 refresh 才是真的没会话。
         */
        private data class Tokens(
            val access: String?,
            val refresh: String,
        )

        private companion object {
            const val KEY_ACCESS_TOKEN = "auth_access_token"
            const val KEY_REFRESH_TOKEN = "auth_refresh_token"
        }
    }
