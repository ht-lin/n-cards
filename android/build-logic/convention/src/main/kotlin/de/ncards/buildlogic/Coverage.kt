package de.ncards.buildlogic

import kotlinx.kover.gradle.plugin.dsl.KoverProjectExtension
import org.gradle.api.Project
import org.gradle.kotlin.dsl.configure

/**
 * §13.3 的 Android 覆盖率门禁：`core:*` 与 `data:*` 行覆盖 ≥ **70%**。
 *
 * **T-011 已在 CI 里打开 `koverVerify`**（.github/workflows/android.yml）。
 *
 * T-008 当初留的判断有一处是错的：它以为空壳模块「分母为 0 → 0/0 视作通过 → 假绿」。
 * T-011 实测**空壳模块确实通过**（main 里没有 .kt 的 12 个模块全绿），但有源码、
 * 没单测的模块会**红**在 `0.000000`，而不是被当成 0/0。所以打开这个门禁的成本不是
 * 「假绿变真红」，而是要为每个有源码的模块给出一个答案 —— 见下面的 [COVERAGE_EXEMPT]。
 *
 * 另一处是分母：Dagger/Hilt 的生成代码原本整个计进来，实测让 `:core:network:impl`
 * 停在 64.5%（手写的 RetryInterceptor 其实是 32/33）。排除生成代码之后它自然过线。
 * 这一条比任何豁免都重要 —— 分母错了，阈值调多少都没有意义。
 *
 * feature:* 与 app 不在门禁内 —— §13.3 的表格只点了 `core:*` 与 `data:*`。
 * UI 层的覆盖率由 §13.4 的 Compose UI Test（J1/J2/J3 三条旅程）保证，
 * 那是另一种度量，不该混进行覆盖率里凑数。
 */
internal fun Project.configureKoverThreshold() {
    val underCoverageGate = path.startsWith(":core:") || path.startsWith(":data:")
    if (!underCoverageGate) return
    if (path in COVERAGE_EXEMPT) return

    extensions.configure<KoverProjectExtension> {
        reports {
            filters {
                excludes {
                    // 生成代码不进分母。T-011 实测：不排除的话 :core:network:impl 是
                    // 64.5%，而缺的那 35% 几乎全是 Dagger/Hilt 的 *_Factory 与
                    // HiltWrapper_*（手写的 RetryInterceptor 是 32/33）。
                    // 那不是「测试不够」，是分母算错了 —— 为生成的工厂类写测试
                    // 等于在测 Dagger，与 :core:network:api 豁免的理由同构。
                    //
                    // 按注解排除是主力（Hilt/Dagger 的产物都带这两个之一，
                    // 且不依赖类名约定）；名字模式兜住 Room 与 Compose 的产物，
                    // 它们不带 @Generated。
                    annotatedBy(
                        "dagger.internal.DaggerGenerated",
                        "javax.annotation.processing.Generated",
                    )
                    classes(
                        "*_Factory",
                        "*_Factory\$*",
                        "*_MembersInjector",
                        "Hilt_*",
                        "*HiltWrapper_*",
                        "*_HiltModules",
                        "*_HiltModules\$*",
                        "*_GeneratedInjector",
                        // Room 的 DAO / Database 实现（T-009 起）
                        "*_Impl",
                        "*_Impl\$*",
                        // Compose 编译器为无参 lambda 生成的持有类
                        "*ComposableSingletons*",
                    )

                    // Hilt 的 @Module 本身是手写的，但它装的是**装配**而不是行为：
                    // 一个 @Provides 写错了，Hilt 在编译期就报 missing binding
                    // （app/build.gradle.kts 的注释记的正是这件事），轮不到行覆盖率来发现。
                    // 把它计进分母，只会逼人写一批「调用 provideX() 断言非 null」的测试。
                    annotatedBy("dagger.Module")
                }
            }
            verify {
                rule {
                    minBound(COVERAGE_MIN_PERCENT)
                }
            }
        }
    }
}

/** §13.3：`core:*` 与 `data:*` 行覆盖 ≥ 70%。 */
private const val COVERAGE_MIN_PERCENT = 70

/**
 * 有理由的豁免。**加一条就要在这里写清为什么**，否则这张表会变成绕开门禁的后门。
 *
 * - `:core:network:api`（T-010）：整个模块是 `openapi-generator` 的产物，
 *   §13.1 第 4 条明令禁止手改。为它写测试等于在测 openapi-generator ——
 *   而真正该被测的是**契约与产物是否一致**，那由
 *   `:core:network:api:checkApiClientUpToDate` 守着，比行覆盖率精确得多。
 *   它的 36 个文件若计入分母，只会逼人写一批没有意义的测试来把比例凑上去。
 *   消费这些类型的行为覆盖在 `:core:network:impl`（那个模块不豁免）。
 *
 * - `:core:database` 与 `:core:crypto`（T-011 决定）：**Kover 只统计单元测试**，
 *   而这两个模块有意义的测试全在 `androidTest` —— SQLCipher 是 JNI，Robolectric
 *   加载不了；`KeystoreAesGcmKeyWrapper` / `KeystoreSecretStore` 要的是真的
 *   AndroidKeyStore，没有替身能证明「篡改密文必须失败」这类断言。
 *   实测 `:core:database` 0%、`:core:crypto` 27.2%（过线的是
 *   `KeystoreDbPassphraseProvider`，它的分支藏在接口后面，单测够得着）。
 *
 *   ⚠️ android/README.md 曾写「core:crypto 没有这个问题（单测覆盖得到）」——
 *   **那句话是错的**，T-011 用数字纠正了它。两个模块是同一个问题，同一条理由。
 *
 *   它们不是「没人管」：31 个仪器测试（crypto 14 / database 17）在 main 流水线的
 *   Gradle Managed Device 上跑，api 26 + api 34 两档。选豁免而不是把仪器测试的
 *   覆盖率并进来，是因为后者要求 PR 流水线里起模拟器，会把 §14.3 的 12 分钟预算
 *   直接冲掉。**这个取舍在第一个真正做 UI 的里程碑（M1）应当重新评估。**
 *
 * - `:core:designsystem`（T-011）：整个模块是 Compose 的主题声明
 *   （Theme.kt / Color.kt / Type.kt，206 行，没有一个分支）。它的正确性由
 *   「看起来对不对」定义，而那是 §13.4 的 Compose UI Test 的事 —— 与下面那段
 *   「UI 层的覆盖率是另一种度量」是同一条理由，只是那段当初只想到了 feature:*。
 */
private val COVERAGE_EXEMPT = setOf(
    ":core:network:api",
    ":core:database",
    ":core:crypto",
    ":core:designsystem",
)
