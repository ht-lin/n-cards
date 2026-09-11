// 纯 Kotlin 领域模型：Card, Member, Friend, BarcodeFormat, SyncState（§12.3）。
//
// ⚠️ 这是全仓库唯一的**非 Android** 模块，用的是 ncards.jvm.library。
// §12.3 对本模块的注释是「纯 Kotlin」—— 做成 kotlin("jvm") 就把那句话变成了
// 结构性约束：这里连 android.* 都 import 不到，而不是靠自觉。
// 想在这里放 @Composable、Context 或任何 androidx 类型，说明那个东西不属于 core:model。
//
// §4.3：客户端生成 UUIDv7 作为主键，所以离线创建的实体从一开始就有稳定 ID。
// 那个生成器将来也住在这里。

// T-151 追加 kotlinx.serialization：navigation-compose 的类型安全路由要求路由类型
// 是 @Serializable（`composable<WalletRoute>` 内部走 `serializer<T>()`）。
// 路由定义住在本模块是 §12.3 的规定 ——「跨 feature 导航经 app 的 NavHost +
// core:model 的路由定义」，ModuleGraph 的违规文案里逐字写着这一条。

plugins {
    id("ncards.jvm.library")
    id("ncards.kotlin.serialization")
}
