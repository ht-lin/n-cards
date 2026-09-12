// Room + SQLCipher，entities / DAO / migrations（§3.4 / §5.2）。由 T-009 交付。
//
// 本模块**不依赖 core:model**：所有列都是 Kotlin 原生类型，枚举以 TEXT 存。
// Entity ↔ Model 的映射按 §12.3 归 data:*（T-153）。好处是 schema 里不会钉死
// BarcodeFormat 的取值域，服务端加一个码制不需要动数据库。

plugins {
    id("ncards.android.library")
    id("ncards.android.room")
    id("ncards.android.hilt")
}

android {
    namespace = "de.ncards.core.database"
}

// ⚠️ 第一次改 schema 的任务需要先解决这个（现在 version = 1，没有可迁移的东西，
// 所以不预先埋一段没人跑过的构建配置）：
//
// MigrationTestHelper 要从 androidTest 的 assets 里读 schemas/*.json，通常写成
// `android { sourceSets.getByName("androidTest").assets.srcDir(...) }`。
// 在 AGP 9 上这句**编译期通过、配置期崩**：
//
//   DefaultAndroidLibrarySourceSet_Decorated cannot be cast to
//   com.android.build.gradle.api.AndroidLibrarySourceSet
//
// Kotlin DSL 为 `sourceSets` 生成的访问器指向的是旧的 `com.android.build.gradle.api`
// 类型，而 AGP 9 的实现类已经不再实现它 —— `getByName` 与 `named { }` 两种写法
// 都一样。这是 README 里「三个 AGP 9 的坑」的同一族问题，出路多半是走
// `androidComponents` 的新 API 或直接把 JSON 复制进 assets。

dependencies {
    implementation(project(":core:crypto"))
    implementation(libs.sqlcipher)
    implementation(libs.timber)

    // T-153：把 Room 的运行时 api 出去，消费方要的是 `NcardsDatabase.withTransaction { }`。
    //
    // ⚠️ 这不是「顺手放开」。跨 DAO 的原子写只有两个落点，而另一个更差：
    // 把编排塞进 core:database（一个 @Transaction 的方法同时改 card_members
    // 和 sync_outbox）。那等于把「置顶要不要入 outbox」这类**策略**写进
    // 持久化层，而 T-009 刻意让本模块「不放任何策略」（SyncOutboxDao 的类注释）。
    //
    // 编排归 data:*（§4.3 分层图：Repository 是唯一决定「数据从哪来」的地方），
    // 所以事务的入口必须对 data:* 可见。
    //
    // room-runtime 由 ncards.android.room 以 implementation 加入；这里只放开
    // room-ktx —— data:* 需要的是 withTransaction 这个扩展，不是 @Dao 的注解处理。
    api(libs.room.ktx)
}
