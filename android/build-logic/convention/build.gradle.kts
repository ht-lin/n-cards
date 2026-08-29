// convention plugin 的宿主。产出 ncards.* 的插件 id，被 android/ 主构建消费。
//
// ⚠️ 为什么是 `Plugin<Project>` 类而不是 `ncards.android.library.gradle.kts` 这种
// **预编译脚本插件**（后者读起来舒服得多）：
//
//   预编译脚本插件的 `plugins { id("com.android.library") }` 要求 AGP 同时在
//   build-logic 的**编译期与运行期** classpath 上，也就是必须写成 `implementation`。
//   而主构建的根 build.gradle.kts 里又用 `alias(libs.plugins.android.library) apply false`
//   声明了同一个插件 —— 两份 AGP 相遇，Gradle 会报
//   「Plugin request for plugin already on the classpath must not include a version」。
//
//   解法只有两个：要么把版本声明从根 build.gradle.kts 里全删掉（版本号从此藏在
//   build-logic 里，与 §12.3「唯一依赖声明处」的可见性意图相悖），要么用
//   `compileOnly` + 命令式插件类。选后者：AGP 只在编译期可见，运行期由消费方的
//   插件 classpath 提供，两边不打架，版本号也留在根 build.gradle.kts 里看得见。
//
// 插件 **id** 与任务书点名的完全一致（ncards.android.{application,library,feature,hilt,room}、
// ncards.kotlin.serialization），见下方 gradlePlugin 块。

plugins {
    `kotlin-dsl`
}

group = "de.ncards.buildlogic"

kotlin {
    compilerOptions {
        jvmTarget.set(org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17)
    }
}

java {
    sourceCompatibility = JavaVersion.VERSION_17
    targetCompatibility = JavaVersion.VERSION_17
}

dependencies {
    // 全部 compileOnly —— 理由见文件头。
    compileOnly(libs.android.gradlePlugin)
    compileOnly(libs.kotlin.gradlePlugin)
    compileOnly(libs.compose.gradlePlugin)
    compileOnly(libs.ksp.gradlePlugin)
    compileOnly(libs.kover.gradlePlugin)
    compileOnly(libs.ktlint.gradlePlugin)
    compileOnly(libs.detekt.gradlePlugin)

    testImplementation(kotlin("test"))
}

tasks.withType<Test>().configureEach {
    useJUnitPlatform()
    testLogging {
        events("passed", "failed", "skipped")
    }
    // ModuleGraphTest 要读 ../../settings.gradle.kts 做覆盖率断言。
    // 用系统属性传路径，而不是让测试去猜工作目录。
    systemProperty("ncards.settingsFile", rootDir.parentFile.resolve("settings.gradle.kts").absolutePath)
}

gradlePlugin {
    plugins {
        // ---- 任务书点名的六个 ------------------------------------------
        register("ncardsAndroidApplication") {
            id = "ncards.android.application"
            implementationClass = "de.ncards.buildlogic.NcardsAndroidApplicationPlugin"
        }
        register("ncardsAndroidLibrary") {
            id = "ncards.android.library"
            implementationClass = "de.ncards.buildlogic.NcardsAndroidLibraryPlugin"
        }
        register("ncardsAndroidFeature") {
            id = "ncards.android.feature"
            implementationClass = "de.ncards.buildlogic.NcardsAndroidFeaturePlugin"
        }
        register("ncardsAndroidHilt") {
            id = "ncards.android.hilt"
            implementationClass = "de.ncards.buildlogic.NcardsAndroidHiltPlugin"
        }
        register("ncardsAndroidRoom") {
            id = "ncards.android.room"
            implementationClass = "de.ncards.buildlogic.NcardsAndroidRoomPlugin"
        }
        register("ncardsKotlinSerialization") {
            id = "ncards.kotlin.serialization"
            implementationClass = "de.ncards.buildlogic.NcardsKotlinSerializationPlugin"
        }

        // ---- 任务书之外的四个内部插件 ----------------------------------
        // 每个的存在理由都写在对应的实现类头部；不要因为「任务书没提」就删。
        register("ncardsJvmLibrary") {
            id = "ncards.jvm.library"
            implementationClass = "de.ncards.buildlogic.NcardsJvmLibraryPlugin"
        }
        register("ncardsAndroidCompose") {
            id = "ncards.android.compose"
            implementationClass = "de.ncards.buildlogic.NcardsAndroidComposePlugin"
        }
        register("ncardsModuleGraph") {
            id = "ncards.module.graph"
            implementationClass = "de.ncards.buildlogic.NcardsModuleGraphPlugin"
        }
        register("ncardsQuality") {
            id = "ncards.quality"
            implementationClass = "de.ncards.buildlogic.NcardsQualityPlugin"
        }
    }
}
