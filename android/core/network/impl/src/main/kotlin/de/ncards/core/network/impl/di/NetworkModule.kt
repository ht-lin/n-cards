package de.ncards.core.network.impl.di

import dagger.Binds
import dagger.Module
import dagger.Provides
import dagger.hilt.InstallIn
import dagger.hilt.components.SingletonComponent
import de.ncards.core.network.api.AuthApi
import de.ncards.core.network.api.CardsApi
import de.ncards.core.network.api.infrastructure.Serializer
import de.ncards.core.network.impl.NetworkConfig
import de.ncards.core.network.impl.error.ApiErrorMapper
import de.ncards.core.network.impl.error.ProblemDetailsApiErrorMapper
import de.ncards.core.network.impl.interceptor.ClientHeaderInterceptor
import de.ncards.core.network.impl.interceptor.RequestIdInterceptor
import de.ncards.core.network.impl.interceptor.RetryInterceptor
import kotlinx.serialization.json.Json
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import retrofit2.converter.kotlinx.serialization.asConverterFactory
import java.util.concurrent.TimeUnit
import javax.inject.Singleton
import kotlin.time.Duration

/**
 * 网络层的 DI 接线。
 *
 * ⚠️ [NetworkConfig] **不在这里提供** —— 它由 `:app` 绑（那里才有 `BuildConfig` 的
 * `versionName` / `versionCode` 与 buildType 决定的 base URL）。少了那个绑定，
 * Hilt 会在编译期报「missing binding」，而不是在运行时连到一个写死的地址上。
 */
@Module
@InstallIn(SingletonComponent::class)
internal object NetworkModule {
    /**
     * §3.10 / §13.6 的硬要求就是这里的 `ignoreUnknownKeys = true`。
     *
     * 离线优先意味着旧客户端长期存在：服务端按 §13.6 加一个响应字段是**允许**的，
     * 老客户端不能因为多了一个键就崩。这一行不是配置项，是契约的一部分 ——
     * `NcardsKotlinSerializationPlugin` 的 KDoc 也点名了它的落点在本模块。
     *
     * `explicitNulls = false` 对应 `docs/api/README.md` 的那条：`errors` / `current`
     * 为空时**该成员不出现**，不会发成 `[]` / `{}`。生成的模型给了默认值，
     * 这一行让「缺席」和「显式 null」在解码时表现一致。
     *
     * `serializersModule` 复用生成的 [Serializer.kotlinxSerializationAdapters] ——
     * 生成产物里剩下的 `@Contextual` 恰好只有 `java.util.UUID` / `LocalDate` /
     * `OffsetDateTime` / `java.net.URI` 四类，而它登记的正是这四类的 adapter。
     * 自己再抄一份等于制造第二个真相源。
     */
    @Provides
    @Singleton
    fun provideJson(): Json =
        Json {
            ignoreUnknownKeys = true
            explicitNulls = false
            serializersModule = Serializer.kotlinxSerializationAdapters
        }

    /**
     * [RetryInterceptor] 退避时真正睡觉的实现。
     *
     * ⚠️ 写成 `object :` 而不是 SAM lambda（`RetryInterceptor.Sleeper { ... }`）是**必须**的：
     * AGP 9 的 `androidx.annotation.experimental.lint.ExperimentalDetector` 在分析
     * 一个 `fun interface` 的 SAM 转换时会崩 ——
     * `NoSuchElementException: Array contains no element matching the predicate`
     * （`ExperimentalDetector.kt:890`），整个 `lintAnalyzeDebug` 直接失败，
     * 而报错信息只说「NetworkModule.kt」，不说是哪一行。
     *
     * 那是 lint 自己的 bug。出路只有两条：在 `lint.xml` 里关掉 `UnsafeOptInUsage`
     * （连带失去一条真有用的检查），或者不写那个 lambda。选后者，代价是三行。
     * 测试里照旧用 SAM lambda —— lint 不分析单测源集。
     */
    @Provides
    @Singleton
    fun provideSleeper(): RetryInterceptor.Sleeper =
        object : RetryInterceptor.Sleeper {
            override fun sleep(duration: Duration) = Thread.sleep(duration.inWholeMilliseconds)
        }

    /**
     * 拦截器顺序**不是**随便排的。先加的在外层：
     *
     * ```
     * ClientHeader → RequestId → Retry → [日志] → 网络
     * ```
     *
     * - `ClientHeader` 最外：它覆盖调用方传进来的 `X-Client`，越早越好。
     * - `RequestId` 在 `Retry` **外面**：一次逻辑调用的多次重试共用同一个
     *   `X-Request-Id`，服务端日志里才看得出「同一个请求重试了 3 次」。
     * - 日志在 `Retry` **里面**：这样每一次真实的网络往返都会被记一行，
     *   而不是只记最后一次。
     *
     * T-150 的认证在这之外还有两个插槽：`Authorization` 由一个拦截器加，
     * 401 静默刷新由 OkHttp 的 `Authenticator` 做（它不是拦截器，
     * 在连接层重发，所以和这里的顺序无关）。
     */
    @Provides
    @Singleton
    fun provideOkHttpClient(
        config: NetworkConfig,
        clientHeader: ClientHeaderInterceptor,
        requestId: RequestIdInterceptor,
        retry: RetryInterceptor,
    ): OkHttpClient {
        val builder = OkHttpClient
            .Builder()
            .connectTimeout(CONNECT_TIMEOUT_SECONDS, TimeUnit.SECONDS)
            .readTimeout(READ_TIMEOUT_SECONDS, TimeUnit.SECONDS)
            .writeTimeout(WRITE_TIMEOUT_SECONDS, TimeUnit.SECONDS)
            // OkHttp 自带的重试只覆盖「连接失败换一条路由再试」，不覆盖 429 / 503，
            // 两者不重叠，都要。
            .retryOnConnectionFailure(true)
            .addInterceptor(clientHeader)
            .addInterceptor(requestId)
            .addInterceptor(retry)

        if (config.enableHttpLogging) {
            // §7.3：release 构建不得输出任何日志，所以这一段由 :app 的
            // BuildConfig.DEBUG 决定装不装。
            //
            // 级别只到 BASIC（方法 / URL / 状态码 / 耗时）：HEADERS 会打出
            // Authorization，BODY 会打出 barcode_value 与 email —— 后两者是
            // T-011 的敏感日志扫描明令禁止的，也是 §3.8 的个人数据。
            val logging = HttpLoggingInterceptor()
            logging.level = HttpLoggingInterceptor.Level.BASIC
            builder.addInterceptor(logging)
        }

        return builder.build()
    }

    @Provides
    @Singleton
    fun provideRetrofit(
        config: NetworkConfig,
        client: OkHttpClient,
        json: Json,
    ): Retrofit =
        Retrofit
            .Builder()
            // baseUrl 必须带 /v1/ 并以 / 结尾 —— 契约的 servers[].url 含 /v1，
            // paths 下是相对路径，生成的注解也是 @GET("cards")。NetworkConfig 的 init 会拦。
            .baseUrl(config.baseUrl)
            .client(client)
            // 成功响应是 application/json; charset=utf-8，错误响应是
            // application/problem+json（§6.1）。后者**不**经过这个 converter ——
            // 它由 ApiErrorMapper 直接读 errorBody() 解析，因为错误体的形状是统一的
            // Problem，而 Retrofit 的 converter 是按返回类型选的。
            .addConverterFactory(json.asConverterFactory(APPLICATION_JSON))
            .build()

    @Provides
    @Singleton
    fun provideAuthApi(retrofit: Retrofit): AuthApi = retrofit.create(AuthApi::class.java)

    @Provides
    @Singleton
    fun provideCardsApi(retrofit: Retrofit): CardsApi = retrofit.create(CardsApi::class.java)

    private val APPLICATION_JSON = "application/json".toMediaType()
    private const val CONNECT_TIMEOUT_SECONDS = 10L

    /**
     * 读超时给到 30 秒：§9.1 的 SLO 是 P95 ≤ 10 s（前台同步），而德国的地铁与地下
     * 超市里 3 秒就断掉只会让 outbox 空转。写超时同理。
     */
    private const val READ_TIMEOUT_SECONDS = 30L
    private const val WRITE_TIMEOUT_SECONDS = 30L
}

/** 接口 → 实现的绑定。与 `core:crypto` 的 `CryptoModule` 一样优先用 `@Binds`。 */
@Module
@InstallIn(SingletonComponent::class)
internal abstract class NetworkBindingsModule {
    @Binds
    @Singleton
    abstract fun bindApiErrorMapper(impl: ProblemDetailsApiErrorMapper): ApiErrorMapper
}
