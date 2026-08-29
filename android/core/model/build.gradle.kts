// 纯 Kotlin 领域模型：Card, Member, Friend, BarcodeFormat, SyncState（§12.3）。
//
// ⚠️ 这是全仓库唯一的**非 Android** 模块，用的是 ncards.jvm.library。
// §12.3 对本模块的注释是「纯 Kotlin」—— 做成 kotlin("jvm") 就把那句话变成了
// 结构性约束：这里连 android.* 都 import 不到，而不是靠自觉。
// 想在这里放 @Composable、Context 或任何 androidx 类型，说明那个东西不属于 core:model。
//
// §4.3：客户端生成 UUIDv7 作为主键，所以离线创建的实体从一开始就有稳定 ID。
// 那个生成器将来也住在这里。

plugins {
    id("ncards.jvm.library")
}
