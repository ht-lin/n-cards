// feature:cardedit —— 手动新增/编辑卡的表单（T-155）。
//
// 它是录入三条路里的第一条，也是它们共同的**终点**：T-156（相机扫描）与
// T-157（从图片录入）拿到码值与码制之后，进的仍然是本模块这个表单，
// 只是那两个字段被预填好了。所以本模块**不要**引 CameraX / ML Kit ——
// 那两卡各自带着自己的采集界面，把结果交到这里。
//
// 写入路径不在这里：§4.3 铁律二的「先 Room 再 outbox」整个住在 data:card 的
// DefaultCardRepository 里，本模块只调用 createCard / updateCard 两个方法。

plugins {
    id("ncards.android.feature")

    // 两个目的地（CardCreateRoute / CardEditRoute）是 @Serializable ——
    // navigation-compose 的类型安全路由要求它（`composable<T>` 内部走 `serializer<T>()`）。
    id("ncards.kotlin.serialization")
}

android {
    namespace = "de.ncards.feature.cardedit"
}

dependencies {
    // §12.3：feature 一律经 Repository 拿数据，看不见 Room 的任何类型。
    implementation(project(":data:card"))

    // 码制展示名（displayNameRes）与**码值校验器**（validateBarcodePayload）。
    //
    // ⚠️ 校验规则住在那边不是偶然：T-152 的落地记录点名要求「逐字段的载荷校验
    // 与德语文案归 T-155，**落点仍在 core:barcode**，不要在 feature:cardedit 里
    // 另起一套」。本模块只负责把 BarcodePayloadProblem 映射成文案。
    implementation(project(":core:barcode"))

    // FakeCardRepository —— T-155 起是全仓唯一的一份，住在 :data:card 的 testFixtures。
    // ⚠️ 两条都要：ViewModel 单测在 test、Compose UI 测试在 androidTest，
    // 而两个源集互相看不见。
    testImplementation(testFixtures(project(":data:card")))
    androidTestImplementation(testFixtures(project(":data:card")))

    // ⚠️ `ncards.android.feature` 只把 :core:testing 挂进 testImplementation。
    // 这里再挂一次到 androidTest，理由见 feature:carddetail 的同一条注释：
    // 两个源集各抄一份 `card(...)` 构造器，是「假绿」最现实的温床。
    androidTestImplementation(project(":core:testing"))
}
