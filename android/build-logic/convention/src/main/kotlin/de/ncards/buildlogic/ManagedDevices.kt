package de.ncards.buildlogic

import com.android.build.api.dsl.CommonExtension
import com.android.build.api.dsl.ManagedVirtualDevice
import org.gradle.api.Project

/**
 * §13.3 的 Instrumentation 门禁：关键路径在 **Gradle Managed Device** 上通过，
 * §14.3 点名 **api 26 + api 34** 两档。由 T-011 接线。
 *
 * 两档的分工是明确的，不是随手选的：
 *
 * - **api 26** 是 `minSdk`（libs.versions.toml），也是 §13.8 里「低端设备
 *   Android 8 + 2 GB RAM」那一条的下界。T-009 的 31 个仪器测试此前**只在 api 34 上
 *   验过** —— Keystore 的 `setUserAuthenticationRequired`、SQLCipher 的 JNI 加载
 *   在 8.0 上是否同样成立，在此之前没有任何东西在证明。
 * - **api 34** 是主力档，用 ATD（Automated Test Device）镜像：没有 Play 服务、
 *   没有大部分系统应用，启动与跑测试都明显快。
 *
 * ⚠️ **api 26 不能用 ATD**：`aosp-atd` 镜像从 API 30 才开始有。26 只能走 `aosp`，
 * 慢一些是必然的。别为了对齐把 26 也写成 aosp-atd —— 那会在 CI 上得到一句
 * 「找不到 system image」，而不是回退。
 *
 * ⚠️ 这套配置挂在**所有** Android 模块上（[configureAndroidCommon]），而不是只挂
 * 有 `androidTest` 的那两个。刻意的：写成白名单，下一个加仪器测试的人不会想到
 * 还要回来改 build-logic，于是他的测试在 CI 上根本不跑，而且是**静默**不跑。
 * 没有 androidTest 源集的模块，对应任务是 NO-SOURCE，不会启动模拟器。
 *
 * 跑法（只在 main 合入流水线里跑，见 .github/workflows/main.yml）：
 *
 *     ./gradlew ncardsGroupDebugAndroidTest
 *
 * 本地跑同一条命令即可，第一次会下载 system image（约 1–2 GB），需要 `/dev/kvm`。
 *
 * ⚠️ **本地跑挂了之后，下一次会卡在设备锁上。** 报错是
 *
 *     TimeoutException: Could not acquire device lock after waiting for 600 seconds.
 *     The limit of 1 concurrent devices has been reached (4 are active).
 *
 * 而此刻一台模拟器都没在跑 —— 计数存在 `~/.android/avd/gradle-managed/`，
 * 构建被杀时不会回滚。出路是错误信息里那一句：
 *
 *     pkill -f qemu-system-x86_64-headless && ./gradlew cleanManagedDevices
 *
 * CI 上不会遇到（每个 job 是全新 runner），这一条是给本地的。
 */
internal fun Project.configureManagedDevices(extension: CommonExtension) {
    val devices = extension.testOptions.managedDevices.allDevices

    val api26 = devices.maybeCreate("api26", ManagedVirtualDevice::class.java).apply {
        device = "Pixel 2"
        apiLevel = MIN_SDK_DEVICE_API
        systemImageSource = "aosp"
        testedAbi = TESTED_ABI
    }

    val api34 = devices.maybeCreate("api34", ManagedVirtualDevice::class.java).apply {
        device = "Pixel 6"
        apiLevel = MAIN_DEVICE_API
        systemImageSource = "aosp-atd"
        testedAbi = TESTED_ABI
    }

    extension.testOptions.managedDevices.groups.maybeCreate("ncards").apply {
        targetDevices.add(api26)
        targetDevices.add(api34)
    }
}

/** `minSdk`（§13.8 的「低端设备」下界）。与 libs.versions.toml 的 minSdk 一致。 */
private const val MIN_SDK_DEVICE_API = 26

/** 主力档。§14.3 点名 api 34。 */
private const val MAIN_DEVICE_API = 34

/**
 * **显式写死，不要删。** AGP 9 不指定时默认 `x86_64`，但会打一条警告说 AGP 10 会把
 * 默认改成 `arm64-v8a`，而上面两个 x86_64 的 system image 不支持 NDK translation ——
 * 到那时**测试会直接跑不起来**，报错还落在模拟器那一侧。
 *
 * 对本项目尤其要紧：SQLCipher 是 JNI，`:core:database` 的 17 个测试全靠加载
 * `libsqlcipher.so`。ABI 选错了不是「慢一点」，是整组测试红。
 *
 * ⚠️ **AGP 9.3.2 上设了这个值，那条警告照样打**（"The device ... does not specify a
 * testedAbi"）。已确认 `ManagedVirtualDevice.setTestedAbi` 确实被调用了（反编译
 * 插件字节码看过），所以是 AGP 那条警告没读 DSL 的值，不是这里没设上。
 * 今天两条路径的实际 ABI 都是 x86_64，62 次测试在 api 26 与 api 34 上全绿。
 * ⚠️ 那 62 个绿是**本地**跑出来的，不构成「CI 上也能跑」的证据 —— 本地有显示设备，
 * CI 的无头 runner 没有，这个差异让 GMD 在 CI 上一台模拟器都起不来（#22，修法在
 * main.yml 的「仪器测试」那一步）。ABI 这一条的结论不受影响，但别拿它当 CI 的凭据。
 * **别因为警告还在就把这行删掉** —— 删了才是真的会在 AGP 10 上炸。
 * 升 AGP 时回头确认一次：警告消失了，或者它开始说别的。
 *
 * 将来真要在 arm64 上跑（比如换 Apple silicon 或 ARM runner），要连着
 * systemImageSource 一起换，而不是只删这一行。
 */
private const val TESTED_ABI = "x86_64"
