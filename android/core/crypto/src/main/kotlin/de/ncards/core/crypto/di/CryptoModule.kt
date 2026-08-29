package de.ncards.core.crypto.di

import dagger.Binds
import dagger.Module
import dagger.hilt.InstallIn
import dagger.hilt.components.SingletonComponent
import de.ncards.core.crypto.DbPassphraseProvider
import de.ncards.core.crypto.KeyWrapper
import de.ncards.core.crypto.KeystoreAesGcmKeyWrapper
import de.ncards.core.crypto.KeystoreDbPassphraseProvider
import de.ncards.core.crypto.KeystoreSecretStore
import de.ncards.core.crypto.SecretStore
import javax.inject.Singleton

/**
 * `core:crypto` 的绑定。三个实现全是 `internal` —— 模块外只看得见接口。
 *
 * 全部用 `@Binds` 而非 `@Provides`：`@Binds` 的抽象方法只是一条类型映射，
 * 实例化交给各实现的 `@Inject constructor`，于是「谁依赖谁」这件事留在
 * 构造函数签名里，而不是散在一堆手写的 provider 方法体中。
 */
@Module
@InstallIn(SingletonComponent::class)
internal abstract class CryptoModule {
    @Binds
    @Singleton
    abstract fun bindKeyWrapper(impl: KeystoreAesGcmKeyWrapper): KeyWrapper

    @Binds
    @Singleton
    abstract fun bindSecretStore(impl: KeystoreSecretStore): SecretStore

    @Binds
    @Singleton
    abstract fun bindDbPassphraseProvider(impl: KeystoreDbPassphraseProvider): DbPassphraseProvider
}
