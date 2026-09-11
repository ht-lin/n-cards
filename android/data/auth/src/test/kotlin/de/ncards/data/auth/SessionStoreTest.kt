package de.ncards.data.auth

import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertNull
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
}
