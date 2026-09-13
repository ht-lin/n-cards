package de.ncards.data.auth

import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.assertSame
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test

@DisplayName("令牌存储")
class SessionStoreTest {
    private val secrets = FakeSecretStore()
    private val store = SessionStore(secrets)

    @Test
    @DisplayName("存进去再读出来是同一对令牌，状态变成已登录")
    fun savesAndReads() {
        store.save(AuthFixtures.session(accessToken = "a1", refreshToken = "r1"))

        assertEquals("a1", store.accessToken())
        assertEquals("r1", store.refreshToken())
        assertEquals(SessionState.SignedIn, store.state.value)
    }

    @Test
    @DisplayName("落盘的形态不是明文")
    fun storesCiphertext() {
        store.save(AuthFixtures.session(accessToken = "a1", refreshToken = "r1"))

        val onDisk = secrets.stored.values.map { it.toString(Charsets.UTF_8) }
        assertFalse(onDisk.any { it == "a1" || it == "r1" }, "落盘的东西与明文相等 —— 加密这一层被绕过了")
    }

    @Test
    @DisplayName("没存过时是未登录，且 refreshToken 为 null")
    fun emptyStoreIsSignedOut() {
        assertNull(store.refreshToken())
        assertEquals(SessionState.SignedOut(SignedOutReason.NeverSignedIn), store.state.value)
    }

    @Test
    @DisplayName("状态初值是 Unknown —— 冷启动第一帧不该把已登录用户弹到登录页")
    fun initialStateIsUnknown() {
        assertEquals(SessionState.Unknown, SessionStore(FakeSecretStore()).state.value)
    }

    /**
     * ⚠️⚠️ 本文件最重要的一条。
     *
     * `KeystoreSecretStore.clear()` 会连**包裹密钥一起丢弃**，而 SQLCipher 的
     * `db_passphrase` 就存在同一个 `ncards_secrets_v1` 文件里、由同一把
     * Keystore 密钥包裹。在登出路径上调它，用户整个本地数据库 ——
     * 连同 `sync_outbox` 里还没推上去的写入 —— 会变成永远解不开的密文。
     *
     * 而这件事**在功能测试里完全看不出来**：登出照常跳登录页，重新登录照常进钱包
     * （那时库已被当成「首次安装」重建）。只有用户自己发现卡没了。
     */
    @Test
    @DisplayName("清会话用 remove 逐条删，绝不调 clear（那会连数据库 passphrase 一起毁掉）")
    fun clearUsesRemoveNotClear() {
        store.save(AuthFixtures.session())

        store.clear(SignedOutReason.UserAction)

        assertEquals(0, secrets.clearCount, "SecretStore.clear() 被调了 —— 见本用例的注释")
        assertTrue(secrets.stored.isEmpty(), "两个令牌都该被 remove 掉")
        assertNull(store.accessToken())
        assertNull(store.refreshToken())
        assertEquals(SessionState.SignedOut(SignedOutReason.UserAction), store.state.value)
    }

    /**
     * `SecretStore.get` 的契约异常，调用方**必须**处理（那份 KDoc 的原话：
     * 「正确反应是清理重来而不是重试」）。范本是 `KeystoreDbPassphraseProvider`
     * 的第三条分支。
     */
    @Test
    @DisplayName("密钥材料已不可解时按未登录处理，并给出可辨认的原因")
    fun handlesUnrecoverableKeyMaterial() {
        // 先正常存一对，再把存储切成「存着但解不开」。
        SessionStore(secrets).save(AuthFixtures.session())
        secrets.unrecoverable = true

        val fresh = SessionStore(secrets)

        assertNull(fresh.refreshToken())
        assertEquals(SessionState.SignedOut(SignedOutReason.KeyMaterialLost), fresh.state.value)
        assertTrue(secrets.stored.isEmpty(), "解不开的密文该被删掉，否则每次启动都要再抛一次")
        assertEquals(0, secrets.clearCount, "恢复路径同样不该调 clear")
    }

    @Test
    @DisplayName("只有 refresh 没有 access 时仍算有会话 —— 两次 put 不是原子的")
    fun refreshAloneIsASession() {
        store.save(AuthFixtures.session())
        // 模拟「进程在两次 put 之间被杀」留下的半截状态。
        secrets.remove("auth_access_token")

        val fresh = SessionStore(secrets)

        assertNull(fresh.accessToken())
        assertEquals("refresh-1", fresh.refreshToken())
        assertEquals(SessionState.SignedIn, fresh.state.value)
    }

    // ---------------------------------------------------------------- T-153

    @Test
    @DisplayName("userId 初值是 null —— 还没读过存储")
    fun userIdStartsNull() {
        assertNull(store.userId.value)
    }

    @Test
    @DisplayName("rememberUser 之后 userId 可读，且跨对象重建仍在")
    fun remembersUserAcrossRestart() {
        store.save(AuthFixtures.session())
        store.rememberUser(AuthFixtures.USER_ID.toString())

        assertEquals(AuthFixtures.USER_ID.toString(), store.userId.value)

        // 重建对象 = 模拟进程重启：它必须跟着令牌一起被读回来。
        val fresh = SessionStore(secrets)
        fresh.refreshToken()

        assertEquals(AuthFixtures.USER_ID.toString(), fresh.userId.value)
    }

    @Test
    @DisplayName("user id 落盘的形态不是明文")
    fun storesUserIdAsCiphertext() {
        store.rememberUser(AuthFixtures.USER_ID.toString())

        val onDisk = secrets.stored.values.map { it.toString(Charsets.UTF_8) }
        assertFalse(onDisk.any { it == AuthFixtures.USER_ID.toString() })
    }

    /**
     * ⚠️⚠️ 本卡最重要的一条。
     *
     * 登出**不清本地库**（那是刻意的：同一个人重新登录不该重拉 200 张卡），
     * 所以 `cards` 与 `card_members` 的行会留在那里。若 user id 比令牌活得久，
     * 下一个在这台设备上登录的人，`observeWallet` 的
     * `JOIN card_members ON m.user_id = :userId` 会**照样命中上一个用户的行** ——
     * 他会看到别人的卡。
     *
     * 这件事在功能测试里看不出来（登出照常、登录照常），只有真的换个人登录才会现形。
     */
    @Test
    @DisplayName("登出必须把 user id 一并清掉，否则下一个用户会看到上一个用户的卡")
    fun clearingSessionAlsoClearsUserId() {
        store.save(AuthFixtures.session())
        store.rememberUser(AuthFixtures.USER_ID.toString())

        store.clear(SignedOutReason.UserAction)

        assertNull(store.userId.value)
        assertTrue(secrets.stored.isEmpty(), "auth_user_id 也该被 remove 掉")
        assertEquals(0, secrets.clearCount, "清 user id 同样不该走 clear()")
    }

    /**
     * T-151 存下的会话里没有 `auth_user_id`。这些设备升上来时令牌是好的，
     * 只是不知道自己是谁 —— 那不是错误状态，不该把他们登出。
     * 补写由 `fetchMe()` 路径上的 `rememberUser` 完成（`AppViewModel` 冷启动会调）。
     */
    @Test
    @DisplayName("令牌在而 user id 缺失时仍然是已登录 —— 老版本升级上来的设备")
    fun missingUserIdIsNotSignedOut() {
        store.save(AuthFixtures.session())

        val fresh = SessionStore(secrets)
        // 存储是懒加载的：不读一次令牌，state 会一直停在 Unknown
        // （那是冷启动第一帧的语义，见 SessionState 的类注释）。
        fresh.refreshToken()

        assertEquals(SessionState.SignedIn, fresh.state.value)
        assertNull(fresh.userId.value)
    }

    /** `fetchMe()` 每次冷启动都会调，不该每次都多过一趟 Keystore。 */
    @Test
    @DisplayName("rememberUser 幂等：写同一个 id 不重复落盘")
    fun rememberUserIsIdempotent() {
        store.rememberUser(AuthFixtures.USER_ID.toString())
        val afterFirst = secrets.stored["auth_user_id"]

        store.rememberUser(AuthFixtures.USER_ID.toString())

        assertSame(afterFirst, secrets.stored["auth_user_id"])
    }
}
