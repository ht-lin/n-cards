package de.ncards.data.card.di

import dagger.Binds
import dagger.Module
import dagger.Provides
import dagger.hilt.InstallIn
import dagger.hilt.components.SingletonComponent
import de.ncards.core.common.dispatcher.DefaultDispatcherProvider
import de.ncards.core.common.dispatcher.DispatcherProvider
import de.ncards.core.model.id.IdGenerator
import de.ncards.core.model.id.UuidV7Generator
import de.ncards.data.card.CardRepository
import de.ncards.data.card.DefaultCardRepository
import de.ncards.data.card.RoomTransactionRunner
import de.ncards.data.card.TransactionRunner
import javax.inject.Singleton

/**
 * `data:card` 的绑定。全部 `@Binds`（与 `AuthModule` / `CryptoModule` 同一个做法：
 * 类型映射交给构造函数签名，不手写 provider）。
 *
 * ⚠️ `:app` 不需要为此改任何东西：`app/build.gradle.kts` 早就有
 * `implementation(project(":data:card"))`（T-009 加的，当时只为让 Hilt 在 DI 根上
 * 看得见 `@Module`）。一旦哪天有人把那一行删了，钱包会在**运行时**
 * 报 missing binding —— 而 Dagger 只校验**可达**的绑定，所以它在
 * `feature:wallet` 真的被接进 NavHost 之前不会响。T-153 接上之后就常驻了。
 */
@Module
@InstallIn(SingletonComponent::class)
internal abstract class CardModule {
    @Binds
    @Singleton
    abstract fun bindCardRepository(impl: DefaultCardRepository): CardRepository

    /** 见 [TransactionRunner]：抽这一层是为了让重排的编排能在裸 JVM 上测。 */
    @Binds
    abstract fun bindTransactionRunner(impl: RoomTransactionRunner): TransactionRunner

    companion object {
        /**
         * `DispatcherProvider` 的生产绑定。
         *
         * ⚠️ 是 `@Provides` 而不是 `@Binds`，因为 `DefaultDispatcherProvider`
         * **没有** `@Inject` 构造函数 —— 它住在 `core:common`，而那个模块连
         * hilt 插件都没上（它是「跨模块的纯工具，不含 UI」，为绑一个无状态对象
         * 给它挂一整套注解处理器不划算）。这里 `new` 一下就够了。
         *
         * 落在本模块而不是 `:app/di`，是因为它**目前唯一的消费者**就是
         * `DefaultCardRepository`：绑定跟着消费者走，比堆在 DI 根上更容易看出
         * 「谁需要它」。第二个模块需要它时（多半是 T-250 的 SyncEngine），
         * 该把这一条**挪**到 `:app/di`，而不是在那边再绑一次 ——
         * 两处提供同一个类型会在编译期报 duplicate binding。
         */
        @Provides
        @Singleton
        fun provideDispatcherProvider(): DispatcherProvider = DefaultDispatcherProvider()

        /**
         * 客户端主键生成器（§4.3 铁律四）。
         *
         * 与上面那条同构，理由也同构：`UuidV7Generator` 住在 `core:model`，
         * 而那是全仓唯一的**非 Android** 模块（`ncards.jvm.library`）——
         * 它连 `javax.inject` 都不该有。所以 `new` 一下。
         *
         * 同样落在本模块而不是 `:app/di`：目前唯一的消费者是
         * [de.ncards.data.card.DefaultCardRepository]。第二个消费者出现时
         * （T-156 / T-157 的扫码录入仍然经本 Repository，所以多半是别的实体）
         * 该把它**挪**到 `:app/di`，而不是再绑一次。
         *
         * `@Singleton` 不是为了省对象：[UuidV7Generator] 持有一个 `Random`，
         * 每次注入都新建一个的话，在某些 JVM 上它们会用相近的种子初始化。
         */
        @Provides
        @Singleton
        fun provideIdGenerator(): IdGenerator = UuidV7Generator()
    }
}
