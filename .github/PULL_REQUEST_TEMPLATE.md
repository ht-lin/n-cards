<!--
标题必须是 Conventional Commit 格式（squash 后即为 main 上的 commit message）。
例：feat(wallet): add card pinning
-->

## 变更说明

<!-- 做了什么、为什么。不是 diff 的复述，是动机与取舍。 -->

关联 issue: #

任务编号: T-

## 契约是否变更

- [ ] 否，本 PR 不涉及 API
- [ ] 是 —— 已改 `docs/api/openapi.yaml`，且**本 PR 内**同步了后端实现、契约测试与 Android 生成代码

若为"是"，勾选：

- [ ] 变更是向后兼容的（新增端点/可选字段/响应字段/枚举值），未在 `/v1` 内删改字段、改类型、把可选变必填或收紧校验（§13.6）
- [ ] 新增枚举值有客户端 `UNKNOWN` 兜底分支
- [ ] 新 schema 设了 `additionalProperties: true`
- [ ] 新端点已配置限流（§7.5）

## 迁移是否向后兼容

- [ ] 否，本 PR 无数据库迁移
- [ ] 是 —— 遵循 expand–contract（§13.5）

若有迁移，勾选：

- [ ] 只加可空列 / 新表，未重命名或删除列
- [ ] 未在无默认值情况下 `SET NOT NULL`；索引用 `CREATE INDEX CONCURRENTLY`
- [ ] `down()` 已实现且验证过 `up` → `down` → `up` 往返
- [ ] 文件顶部注释写明：影响的表 / 预估执行时长 / 是否锁表 / 如何回滚

## 测试说明

<!-- 新增/修改了哪些测试，如何验证。写"跑了一下没问题"等于没写。 -->

- [ ] 单元测试覆盖新增逻辑分支
- [ ] 集成测试覆盖新增端点
- [ ] 在真机（低端设备：Android 8 + 2 GB RAM）上验证过（若涉及客户端）

## UI 截图 / 录屏

<!-- UI 变更必填，德语 + 英语各一张。无 UI 变更写"N/A"。 -->

## Definition of Done（§13.8）

- [ ] CI 全绿
- [ ] 德语 + 英语文案齐全（无 `MissingTranslation`）
- [ ] 无障碍检查通过（触摸目标 ≥ 48dp、contentDescription、对比度 ≥ 4.5:1）
- [ ] 若引入个人数据处理 → §8 ROPA 已更新
- [ ] 若需要运维介入 → runbook 已写
- [ ] 若属于 §13.7 的四类决策 → 已写 ADR
- [ ] 未夹带 §1.3 OUT 清单中的功能
