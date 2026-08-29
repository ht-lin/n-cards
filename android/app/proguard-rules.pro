# R8 规则（§7.3：release 必须 minifyEnabled + 资源压缩）。
#
# 骨架期为空是**正常的**：Hilt / Room / kotlinx.serialization 都自带
# consumer 规则，不需要在这里重复。
#
# 真正需要往这里加的只有两类：
#   1. 反射访问的类（本项目应当没有 —— 序列化走 kotlinx 的编译期插件，不是反射）
#   2. Sentry 的行号映射（T-405 接入时补）
#
# ⚠️ 不要为了「先让 release 跑起来」加 `-keep class de.ncards.** { *; }`。
# 那等于关掉 R8，§9.1 的包体预算与 §7.3 的混淆要求会同时失效，
# 而且没人会记得回来删。
