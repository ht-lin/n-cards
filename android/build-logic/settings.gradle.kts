// build-logic 是一个独立的 included build，有自己的 settings。
//
// 它必须吃**同一份** libs.versions.toml —— §12.3 的「唯一依赖声明处」如果对
// convention plugin 不生效，那么版本号就有了第二个真相源，而那个真相源恰好是
// 决定所有模块版本的那个。

dependencyResolutionManagement {
    repositories {
        google()
        mavenCentral()
        gradlePluginPortal()
    }

    versionCatalogs {
        create("libs") {
            from(files("../gradle/libs.versions.toml"))
        }
    }
}

rootProject.name = "build-logic"

include(":convention")
