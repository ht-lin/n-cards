package de.ncards.buildlogic

import kotlinx.kover.gradle.plugin.dsl.KoverProjectExtension
import org.gradle.api.Project
import org.gradle.kotlin.dsl.configure

/**
 * §13.3 的 Android 覆盖率门禁：`core:*` 与 `data:*` 行覆盖 ≥ **70%**。
 *
 * ⚠️ **阈值现在就配上，但 CI 里暂不跑 `koverVerify`** —— 照 T-002 对后端覆盖率
 * 「此刻还没代码，配置先就位」的同一个做法。
 *
 * 为什么不现在就开：T-008 交付的 30 个模块里有 28 个是空壳，分母为 0。
 * 这时候跑 verify 只会得到一个**假绿**（0/0 视作通过），然后在第一个真正写代码的
 * 任务里突然变红，而那个任务的作者会以为是自己写的代码有问题。
 *
 * 谁来打开：**T-011**（CI 流水线），在 T-009 / T-010 让 core:* 与 data:* 有了真实
 * 代码之后，把 `koverVerify` 加进 .github/workflows/android.yml 的步骤里。
 * 本函数已经把阈值与作用范围定好，届时不需要再改 build-logic。
 *
 * feature:* 与 app 不在门禁内 —— §13.3 的表格只点了 `core:*` 与 `data:*`。
 * UI 层的覆盖率由 §13.4 的 Compose UI Test（J1/J2/J3 三条旅程）保证，
 * 那是另一种度量，不该混进行覆盖率里凑数。
 */
internal fun Project.configureKoverThreshold() {
    val underCoverageGate = path.startsWith(":core:") || path.startsWith(":data:")
    if (!underCoverageGate) return

    extensions.configure<KoverProjectExtension> {
        reports {
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
